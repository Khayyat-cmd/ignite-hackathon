# AMAN ML v1.4

This directory contains only the final AMAN v1.4 hackathon model, its Colab
training inputs, and the deployable inference service.

## Final files

- `notebooks/AMAN_Training_Colab_v1.4_FINAL.ipynb`: final Colab notebook.
- `bundles/AMAN_Colab_Training_v1.4_FINAL.zip`: final self-contained training
  bundle. It is intentionally Git-ignored because it is 232 MB.
- `deploy/v1.4/`: frozen models, runtime code, evaluation evidence, API service,
  request example, and integration documentation.

## Train on Google Colab

1. Upload `AMAN_Training_Colab_v1.4_FINAL.ipynb` with **File > Upload notebook**.
2. Keep the runtime on **CPU**. The pinned LightGBM build does not use a T4 GPU.
3. Upload `AMAN_Colab_Training_v1.4_FINAL.zip` to the Colab Files sidebar without
   extracting it.
4. Wait until the upload indicator finishes, then select **Runtime > Run all**.
5. Download both result ZIPs created by the final cell.

Training executes on Colab, not on the laptop. V1.4 uses synthetic simulation and
must not be presented as validated real-world crowd-safety performance.

## Run inference

```powershell
cd deploy/v1.4
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
python service.py
```

The service listens on `http://127.0.0.1:8090` and exposes:

- `GET /health`
- `GET /v1/model-contract`
- `POST /v1/predict`
- `POST /v1/reset-event`

The Laravel backend should call the inference service. The frontend and Unity
simulation should consume the backend's broadcast response. See
`deploy/v1.4/README.md` and `deploy/v1.4/INTEGRATION.md` for the exact 55-feature
request contract and stateful alert behavior.
