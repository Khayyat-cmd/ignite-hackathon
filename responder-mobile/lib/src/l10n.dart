import 'package:flutter/widgets.dart';

/// Field copy for the responder app. English is the source language; the
/// catalogue test enforces that Arabic defines every key English does.
///
/// Placeholders are `{name}`. Nothing here reaches the backend as protocol —
/// mission `kind` values stay English on the wire; only what a responder reads
/// and the report bodies they send are translated.

const supportedLanguages = ['en', 'ar'];

/// Each language is written in its own script, so a responder can find theirs
/// without reading the other one first.
const languageNames = {'en': 'English', 'ar': 'العربية'};

const _en = <String, String>{
  'brand.fieldResponse': 'FIELD RESPONSE',
  'brand.switchResponder': 'Switch responder',
  'brand.changeServer': 'Change server',
  'brand.language': 'Language',

  'demo.notice': 'REHEARSAL · Missions and locations are simulated.',
  'common.retry': 'Retry',
  'common.cancel': 'CANCEL',

  'server.title': 'Connect to AMAN',
  'server.blurb': 'Enter the IP address shown as “Phone API” when the operations console starts.',
  'server.field': 'Computer IP address',
  'server.invalid': 'Enter a valid IP address or server URL.',
  'server.connecting': 'CONNECTING…',
  'server.connect': 'CONNECT',
  'server.sameNetwork':
      'The phone and computer must be on the same Wi-Fi network.',

  'entry.title': 'Choose your profile',
  'entry.blurb': 'Select the current event, then choose your name.',
  'entry.event': 'Rehearsal event',
  'entry.responder': 'RESPONDER',
  'entry.eventOption': '{event} · {status}',
  'entry.eventNumbered': 'Event #{number} · {name}',
  'entry.eventFallback': 'Rehearsal event',

  'directory.emptyTitle': 'No event available',
  'directory.emptyBody':
      'Start a rehearsal from the operations console, then refresh.',

  'mission.assignedArea': 'Assigned area',
  'mission.current': 'Current assignment',
  'mission.channel': 'Mission channel',
  'mission.refreshNote': 'Refreshes every 5 seconds · Pull down to refresh',
  'mission.newSnack':
      'New assignment received. Review the destination and acknowledge.',
  'mission.emptyTitle': 'No active assignment',
  'mission.emptyBody':
      'Remain available. New assignments appear here automatically.',
  'mission.acknowledged': 'ACKNOWLEDGED',
  'mission.actionRequired': 'ACTION REQUIRED',
  'mission.new': 'NEW ASSIGNMENT',
  'mission.rehearsal': 'REHEARSAL',
  'mission.reportTo': 'REPORT TO',
  'mission.onSceneStrip': 'On scene · Crowd response in progress',
  'mission.locationVerifiedArrival': 'Arrival verified for the assigned area.',
  'mission.locationVerified':
      'Location verified. Confirm when you are ready to begin.',
  'mission.locationProceed':
      'Proceed to the assigned area. Arrival will be verified automatically.',
  'brief.label': 'CONTROL ROOM BRIEF',
  'brief.critical': 'CRITICAL',
  'brief.criticalZone':
      'Crowd density in this zone is above the critical threshold.',
  'brief.congested': 'Mobile data is congested at this zone. Use radio if a message does not go through.',

  'action.headingThere': 'HEADING THERE',
  'action.onScene': 'ON SCENE',
  'action.updating': 'UPDATING…',
  'action.acknowledge': 'ACKNOWLEDGE ASSIGNMENT',
  'action.viewDetails': 'VIEW DETAILS FIRST',

  'report.enRoute': 'Heading to {zone}.',
  'report.onScene': 'On scene at {zone}. Beginning crowd response.',

  'thread.empty':
      'No messages yet. Instructions from the control room will appear here.',
  'thread.controlRoom': 'Control room',
  'thread.you': 'You',
  'thread.hint': 'Update control room',
  'thread.send': 'Send update',
  'thread.unread': '{count} NEW',
  'thread.entry': '{sender} · {time}',

  'kind.en_route': 'Heading there',
  'kind.on_scene': 'On scene',

  'link.connecting': 'CONNECTING',
  'link.offline': 'OFFLINE',
  'link.reconnecting': 'RECONNECTING',
  'link.delayed': 'DELAYED',
  'link.live': 'LIVE',
  'link.state': '{state} · {age}',
  'link.justNow': 'just now',
  'link.secondsAgo': '{count}s ago',
  'link.minutesAgo': '{count}m ago',
  'link.hoursAgo': '{count}h ago',

  'error.incomplete': 'The request could not be completed.',
  'error.timeout': 'The connection timed out. Try again.',
  'error.unreachable': 'AMAN could not reach the server.',
  'error.malformed': 'The server returned an unexpected response.',

  'status.running': 'RUNNING',
  'status.paused': 'PAUSED',
  'status.stopped': 'STOPPED',

  'role.crowd_marshal': 'Crowd responder',
  'role.responder': 'Responder',
};

const _ar = <String, String>{
  'brand.fieldResponse': 'الاستجابة الميدانية',
  'brand.switchResponder': 'تبديل المستجيب',
  'brand.changeServer': 'تغيير الخادم',
  'brand.language': 'اللغة',

  'demo.notice': 'تدريب · المهام والمواقع محاكاة.',
  'common.retry': 'إعادة المحاولة',
  'common.cancel': 'إلغاء',

  'server.title': 'الاتصال بـ AMAN',
  'server.blurb':
      'أدخل عنوان الـ IP الظاهر باسم «Phone API» عند تشغيل منصة العمليات.',
  'server.field': 'عنوان IP للحاسوب',
  'server.invalid': 'أدخل عنوان IP أو رابط خادم صحيحًا.',
  'server.connecting': 'جارٍ الاتصال…',
  'server.connect': 'اتصال',
  'server.sameNetwork': 'يجب أن يكون الهاتف والحاسوب على شبكة Wi-Fi نفسها.',

  'entry.title': 'اختر ملفك',
  'entry.blurb': 'اختر الحدث الحالي، ثم اختر اسمك.',
  'entry.event': 'الحدث التدريبي',
  'entry.responder': 'المستجيب',
  'entry.eventOption': '{event} · {status}',
  'entry.eventNumbered': 'الحدث رقم {number} · {name}',
  'entry.eventFallback': 'حدث تدريبي',

  'directory.emptyTitle': 'لا يوجد حدث متاح',
  'directory.emptyBody': 'ابدأ تدريبًا من منصة العمليات، ثم حدِّث الصفحة.',

  'mission.assignedArea': 'المنطقة المكلَّف بها',
  'mission.current': 'التكليف الحالي',
  'mission.channel': 'قناة المهمة',
  'mission.refreshNote': 'يُحدَّث كل 5 ثوانٍ · اسحب للأسفل للتحديث',
  'mission.newSnack': 'وصل تكليف جديد. راجع الوجهة ثم أكّد الاستلام.',
  'mission.emptyTitle': 'لا يوجد تكليف نشط',
  'mission.emptyBody': 'ابقَ متاحًا. تظهر التكليفات الجديدة هنا تلقائيًا.',
  'mission.acknowledged': 'تم الاستلام',
  'mission.actionRequired': 'مطلوب إجراء',
  'mission.new': 'تكليف جديد',
  'mission.rehearsal': 'تدريب',
  'mission.reportTo': 'التوجّه إلى',
  'mission.onSceneStrip': 'في الموقع · الاستجابة للحشد جارية',
  'mission.locationVerifiedArrival':
      'تم التحقق من الوصول إلى المنطقة المكلَّف بها.',
  'mission.locationVerified':
      'تم التحقق من الموقع. أكّد عندما تكون جاهزًا للبدء.',
  'mission.locationProceed':
      'توجّه إلى المنطقة المكلَّف بها. سيتم التحقق من الوصول تلقائيًا.',
  'brief.label': 'موجز غرفة التحكّم',
  'brief.critical': 'حرج',
  'brief.criticalZone': 'كثافة الحشد في هذه المنطقة تجاوزت الحدّ الحرج.',
  'brief.congested': 'شبكة البيانات مزدحمة في هذه المنطقة. استخدم الراديو إذا لم تُسلَّم الرسالة.',

  'action.headingThere': 'في الطريق',
  'action.onScene': 'في الموقع',
  'action.updating': 'جارٍ التحديث…',
  'action.acknowledge': 'تأكيد استلام التكليف',
  'action.viewDetails': 'عرض التفاصيل أولًا',

  'report.enRoute': 'في الطريق إلى {zone}.',
  'report.onScene': 'في الموقع عند {zone}. بدء الاستجابة للحشد.',

  'thread.empty': 'لا توجد رسائل بعد. ستظهر هنا تعليمات غرفة العمليات.',
  'thread.controlRoom': 'غرفة العمليات',
  'thread.you': 'أنت',
  'thread.hint': 'حدِّث غرفة العمليات',
  'thread.send': 'إرسال تحديث',
  'thread.unread': '{count} جديدة',
  'thread.entry': '{sender} · {time}',

  'kind.en_route': 'في الطريق',
  'kind.on_scene': 'في الموقع',

  'link.connecting': 'جارٍ الاتصال',
  'link.offline': 'غير متصل',
  'link.reconnecting': 'إعادة الاتصال',
  'link.delayed': 'متأخر',
  'link.live': 'مباشر',
  'link.state': '{state} · {age}',
  'link.justNow': 'الآن',
  'link.secondsAgo': 'قبل {count} ث',
  'link.minutesAgo': 'قبل {count} د',
  'link.hoursAgo': 'قبل {count} س',

  'error.incomplete': 'تعذّر إتمام الطلب.',
  'error.timeout': 'انتهت مهلة الاتصال. حاول مرة أخرى.',
  'error.unreachable': 'تعذّر على AMAN الوصول إلى الخادم.',
  'error.malformed': 'أعاد الخادم استجابة غير متوقعة.',

  'status.running': 'جارٍ',
  'status.paused': 'متوقف مؤقتًا',
  'status.stopped': 'متوقف',

  'role.crowd_marshal': 'مستجيب حشود',
  'role.responder': 'مستجيب',
};

const catalogues = <String, Map<String, String>>{'en': _en, 'ar': _ar};

class Strings {
  const Strings(this.language);

  final String language;

  static const english = Strings('en');

  Map<String, String> get _table => catalogues[language] ?? _en;

  bool get isRtl => language == 'ar';

  String t(String key, [Map<String, Object?> vars = const {}]) {
    final template = _table[key] ?? _en[key] ?? key;
    if (vars.isEmpty) return template;
    return template.replaceAllMapped(
      RegExp(r'\{(\w+)\}'),
      (match) => vars[match.group(1)]?.toString() ?? match.group(0)!,
    );
  }

  /// Backend vocabulary translated by value, humanised when this catalogue has
  /// never seen it, so a new status still reads as words in the field.
  String _vocab(String prefix, String value) =>
      _table['$prefix.$value'] ??
      _en['$prefix.$value'] ??
      value.replaceAll('_', ' ');

  String kind(String value) => _vocab('kind', value);

  String role(String value) => _vocab('role', value);

  String runStatus(String value) => _vocab('status', value).toUpperCase();

  String zoneLabel(String name) =>
      name.isEmpty ? t('mission.assignedArea') : name;

  String eventLabel(int? number, String name) {
    final title = name.isEmpty ? t('entry.eventFallback') : name;
    return number == null
        ? title
        : t('entry.eventNumbered', {'number': number, 'name': title});
  }
}

/// Carries the active language and the way to change it. Installed through
/// `MaterialApp.builder` so pushed routes — the full-screen assignment takeover
/// among them — sit under it too.
class L10n extends InheritedWidget {
  const L10n({
    super.key,
    required this.strings,
    required this.onChangeLanguage,
    required super.child,
  });

  final Strings strings;
  final ValueChanged<String> onChangeLanguage;

  /// English outside a scope, so a widget test that pumps one screen on its own
  /// still renders copy rather than raw keys.
  static L10n? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<L10n>();

  static Strings of(BuildContext context) =>
      maybeOf(context)?.strings ?? Strings.english;

  @override
  bool updateShouldNotify(L10n oldWidget) =>
      oldWidget.strings.language != strings.language;
}
