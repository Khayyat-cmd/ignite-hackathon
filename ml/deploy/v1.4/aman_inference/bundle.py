"""Portable, hashed training bundle. Hashes ensure integrity, not publisher identity."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path, PurePosixPath
import tempfile
import zipfile

import pyarrow.parquet as pq


def sha256(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1 << 20), b''):
            digest.update(block)
    return digest.hexdigest()


def write_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix+'.tmp')
    temporary.write_text(json.dumps(value, indent=2, sort_keys=True, allow_nan=False)+'\n', encoding='utf-8')
    temporary.replace(path)


def verify_bundle(root):
    root = Path(root).resolve()
    manifest = json.loads((root/'bundle_manifest.json').read_text())
    if manifest.get('bundle_version') != '1.1.0':
        raise ValueError('Unsupported bundle version; rebuild the bundle')
    for name, expected in manifest['files'].items():
        target = (root/name).resolve()
        if not target.is_relative_to(root) or not target.is_file() or sha256(target) != expected:
            raise ValueError(f'Missing, unsafe, or corrupted bundle file: {name}')
    schema = json.loads((root/'feature_schema.json').read_text())
    if schema['schema_version'] != '1.1.0':
        raise ValueError('Training requires dataset schema 1.1.0')
    from .features import MODEL_FEATURE_COLUMNS, IDENTIFIER_COLUMNS, LABEL_COLUMNS
    features = [entry['name'] for entry in schema['model_features']]
    if features != MODEL_FEATURE_COLUMNS:
        raise ValueError('Feature allowlist/order mismatch')
    model_schema = pq.read_schema(root/'model_dataset.parquet')
    if model_schema.names != IDENTIFIER_COLUMNS + features + LABEL_COLUMNS:
        raise ValueError('Model table columns do not match approved schema')
    timeline_columns = pq.read_schema(root/'evaluation_timeline.parquet').names
    if not {'run_id','zone_id','tick','currently_critical','label_available','policy_confounded'}.issubset(timeline_columns):
        raise ValueError('Incomplete event timeline')
    metadata = json.loads((root/'dataset_metadata.json').read_text())
    validation = json.loads((root/'validation_summary.json').read_text())
    if metadata['source'] != 'synthetic_simulated' or not metadata['validation_passed'] or not validation['passed']:
        raise ValueError('Dataset failed validation or source mismatch')
    for name in ['model_dataset.parquet','scenario_metadata.parquet','feature_schema.json','evaluation_timeline.parquet','inference_extra.parquet']:
        if manifest['files'][name] != metadata['artifact_sha256'][name]:
            raise ValueError(f'Dataset metadata hash mismatch: {name}')
    return manifest, schema, metadata


def prepare_bundle(dataset, destination, ml_root):
    dataset, destination, ml_root = Path(dataset), Path(destination), Path(ml_root)
    metadata = json.loads((dataset/'dataset_metadata.json').read_text())
    if metadata['schema_version'] != '1.1.0' or not metadata['validation_passed']:
        raise ValueError('Regenerate and validate schema 1.1.0 before bundling')
    names = ['model_dataset.parquet','scenario_metadata.parquet','feature_schema.json',
             'evaluation_timeline.parquet','inference_extra.parquet','dataset_metadata.json',
             'validation_summary.json','split_audit.json']
    names += [f'splits/{split}_runs.json' for split in ['train','validation','test','stress']]
    paths = {name:dataset/name for name in names}
    paths['requirements-colab.txt'] = ml_root/'requirements-colab.txt'
    for source in (ml_root/'src'/'aman_ml').glob('*.py'):
        if source.stem in {'__init__','features','bundle','metrics','colab_training','inference','run_colab'}:
            paths['runtime/aman_ml/'+source.name] = source
    if destination.exists():
        raise FileExistsError(f'Bundle already exists: {destination}; choose a new name')
    for name in names:
        if name in metadata['artifact_sha256'] and sha256(paths[name]) != metadata['artifact_sha256'][name]:
            raise ValueError(f'Original dataset artifact hash mismatch: {name}')
    manifest = dict(bundle_version='1.1.0',source='synthetic_simulated',
                    files={name:sha256(path) for name,path in paths.items()},
                    omitted=['observations.parquet','ground_truth.parquet'],
                    note='Use only trusted team bundles. Hashes detect corruption, not malicious authors.')
    destination.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(destination,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=3) as z:
        for name,path in paths.items():
            z.write(path,name)
        z.writestr('bundle_manifest.json',json.dumps(manifest,indent=2,sort_keys=True))
    return dict(path=str(destination.resolve()),bytes=destination.stat().st_size,sha256=sha256(destination))


def extract_bundle(archive, parent):
    parent = Path(parent)
    parent.mkdir(parents=True,exist_ok=True)
    target = Path(tempfile.mkdtemp(prefix='aman-bundle-',dir=parent)).resolve()
    with zipfile.ZipFile(archive) as z:
        names = z.namelist()
        if len(names) != len(set(names)) or sum(i.file_size for i in z.infolist()) > 2_000_000_000:
            raise ValueError('Duplicate entries or oversized bundle')
        for name in names:
            p = PurePosixPath(name)
            if '\\' in name or ':' in name or p.is_absolute() or '..' in p.parts:
                raise ValueError('Unsafe archive path')
        z.extractall(target)
    verify_bundle(target)
    return target
