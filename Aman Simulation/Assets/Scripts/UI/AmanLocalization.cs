using System;
using UnityEngine;

namespace Aman.UI
{
    public enum AmanLanguage
    {
        English,
        Arabic
    }

    public static class AmanLocalization
    {
        private const string PreferenceKey = "aman.ui.language";
        private static AmanLanguage current = PlayerPrefs.GetInt(PreferenceKey, 0) == 1
            ? AmanLanguage.Arabic
            : AmanLanguage.English;

        public static event Action LanguageChanged;
        public static AmanLanguage Current => current;
        public static bool IsArabic => current == AmanLanguage.Arabic;

        public static void Toggle() => SetLanguage(IsArabic ? AmanLanguage.English : AmanLanguage.Arabic);

        public static void SetLanguage(AmanLanguage language)
        {
            if (current == language) return;
            current = language;
            PlayerPrefs.SetInt(PreferenceKey, IsArabic ? 1 : 0);
            PlayerPrefs.Save();
            LanguageChanged?.Invoke();
        }

        public static string Text(string english, string arabic) => IsArabic ? arabic : english;

        public static string Zone(string name)
        {
            if (!IsArabic || string.IsNullOrEmpty(name)) return name;
            return name switch
            {
                "North Concourse" => "الرواق الشمالي",
                "South Concourse" => "الرواق الجنوبي",
                "East Stand" => "المدرج الشرقي",
                "West Stand" => "المدرج الغربي",
                "Central Pitch" => "أرضية الملعب",
                "East Entrance" => "المدخل الشرقي",
                "West Exit" => "المخرج الغربي",
                _ => name
            };
        }

        public static string Status(string status)
        {
            if (string.IsNullOrEmpty(status)) return Text("UNKNOWN", "غير معروف");
            string normalized = status.Replace(' ', '_').ToLowerInvariant();
            if (!IsArabic) return normalized.Replace('_', ' ').ToUpperInvariant();
            return normalized switch
            {
                "detected" => "تم الرصد",
                "awaiting_approval" => "بانتظار الموافقة",
                "dispatched" => "تم إرسال المستجيب",
                "acknowledged" => "تم التأكيد",
                "resolved" => "تم الحل",
                "running" => "قيد التشغيل",
                "paused" => "متوقف مؤقتاً",
                "stopped" => "متوقف",
                "available" => "متاح",
                "unavailable" => "غير متاح",
                _ => status
            };
        }

        public static string Connection(string message)
        {
            if (!IsArabic || string.IsNullOrEmpty(message)) return message;
            return message switch
            {
                "CONNECTING" => "جارٍ الاتصال",
                "NO SIMULATION RUN" => "لا توجد محاكاة",
                "INVALID BACKEND RESPONSE" => "استجابة الخادم غير صالحة",
                "DATA OUTDATED" => "البيانات قديمة",
                "LOCAL SIMULATION" => "محاكاة محلية",
                "OFFLINE / LOCAL" => "غير متصل / محلي",
                "BACKEND UNAVAILABLE" => "الخادم غير متاح",
                _ => Status(message)
            };
        }
    }
}
