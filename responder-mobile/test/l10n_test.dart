import 'package:aman_responder/src/l10n.dart';
import 'package:aman_responder/src/models.dart';
import 'package:aman_responder/src/responder_app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'widget_test.dart' show FakeGateway, MissionGateway, onDuty;

final _placeholder = RegExp(r'\{(\w+)\}');

List<String> _names(String value) =>
    (_placeholder.allMatches(value).map((m) => m.group(1)!).toList()..sort());

Widget _scoped(String language, Widget child) => MaterialApp(
  theme: responderTheme,
  locale: Locale(language),
  supportedLocales: supportedLanguages.map(Locale.new).toList(),
  localizationsDelegates: const [
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  builder: (context, built) => L10n(
    strings: Strings(language),
    onChangeLanguage: (_) {},
    child: built ?? const SizedBox.shrink(),
  ),
  home: child,
);

void main() {
  final english = catalogues['en']!;
  final arabic = catalogues['ar']!;

  group('catalogue', () {
    test('translates every English key into Arabic', () {
      expect(english.keys.where((key) => !arabic.containsKey(key)), isEmpty);
    });

    test('keeps the same placeholders in both languages', () {
      final mismatched = english.keys.where(
        (key) => _names(english[key]!).join() != _names(arabic[key]!).join(),
      );
      expect(mismatched, isEmpty);
    });

    test('leaves no Arabic string still written in Latin script', () {
      // Placeholder names are Latin by definition, so they come out first.
      // "AMAN" and "Wi-Fi" are the product and protocol names, kept as written.
      final untranslated = english.keys.where((key) {
        final stripped = arabic[key]!
            .replaceAll(_placeholder, '')
            .replaceAll('AMAN', '')
            .replaceAll('Wi-Fi', '')
            .replaceAll('Phone API', '')
            .replaceAll('IP', '');
        return RegExp('[A-Za-z]').hasMatch(stripped);
      });
      expect(untranslated, isEmpty);
    });
  });

  group('strings', () {
    test('reports the writing direction for each language', () {
      expect(supportedLanguages, ['en', 'ar']);
      expect(const Strings('en').isRtl, isFalse);
      expect(const Strings('ar').isRtl, isTrue);
    });

    test('humanises backend vocabulary this catalogue has never seen', () {
      const s = Strings('ar');
      expect(s.kind('en_route'), 'في الطريق');
      expect(s.kind('some_new_kind'), 'some new kind');
      expect(s.role('crowd_marshal'), 'مستجيب حشود');
      expect(s.runStatus('running'), 'جارٍ');
    });

    test('falls back to a named area when the backend sends none', () {
      expect(const Strings('ar').zoneLabel(''), 'المنطقة المكلَّف بها');
      expect(const Strings('ar').zoneLabel('East Entrance'), 'East Entrance');
      expect(
        const Strings('ar').eventLabel(3, 'Stadium rehearsal'),
        'الحدث رقم 3 · Stadium rehearsal',
      );
      expect(const Strings('en').eventLabel(null, ''), 'Rehearsal event');
    });
  });

  testWidgets('renders the mission screen in Arabic, right to left', (
    tester,
  ) async {
    final gateway = MissionGateway(
      mission: const Mission(
        id: 'm1',
        status: 'dispatched',
        zoneName: 'East Entrance',
        simulated: true,
      ),
    );
    await tester.pumpWidget(
      _scoped(
        'ar',
        MissionHome(gateway: gateway, responder: onDuty, onSwitch: () {}),
      ),
    );
    await tester.pump();
    await tester.pump();

    expect(find.text('التكليف الحالي'), findsOneWidget);
    expect(find.text('تأكيد استلام التكليف'), findsOneWidget);
    expect(find.text('مطلوب إجراء'), findsOneWidget);
    expect(find.text('Current assignment'), findsNothing);
    expect(find.text('ACKNOWLEDGE ASSIGNMENT'), findsNothing);

    final direction = Directionality.of(
      tester.element(find.text('التكليف الحالي')),
    );
    expect(direction, TextDirection.rtl);

    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('switches language from the header and remembers the choice', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(AmanResponderApp(gateway: FakeGateway()));
    await tester.pumpAndSettle();

    expect(find.text('Choose your profile'), findsOneWidget);

    await tester.tap(find.text('العربية'));
    await tester.pumpAndSettle();

    expect(find.text('اختر ملفك'), findsOneWidget);
    expect(find.text('Choose your profile'), findsNothing);
    expect(
      Directionality.of(tester.element(find.text('اختر ملفك'))),
      TextDirection.rtl,
    );

    final saved = await SharedPreferences.getInstance();
    expect(saved.getString('app_language'), 'ar');

    await tester.tap(find.text('English'));
    await tester.pumpAndSettle();
    expect(find.text('Choose your profile'), findsOneWidget);
  });
}
