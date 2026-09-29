import 'package:flutter/material.dart';

@immutable
class MailDeskColors extends ThemeExtension<MailDeskColors> {
  const MailDeskColors({
    required this.bg,
    required this.panel,
    required this.border,
    required this.muted,
    required this.text,
    required this.secondary,
    required this.accent,
    required this.onAccent,
    required this.bubble,
  });

  final Color bg;
  final Color panel;
  final Color border;
  final Color muted;
  final Color text;
  final Color secondary;
  final Color accent;
  final Color onAccent;
  final Color bubble;

  static const light = MailDeskColors(
    bg: Color(0xFFF4F4F5),
    panel: Color(0xFFFFFFFF),
    border: Color(0xFFE4E4E7),
    muted: Color(0xFF71717A),
    text: Color(0xFF18181B),
    secondary: Color(0xFF52525B),
    accent: Color(0xFF0891B2),
    onAccent: Color(0xFFFFFFFF),
    bubble: Color(0xFFF4F4F5),
  );

  static const dark = MailDeskColors(
    bg: Color(0xFF000000),
    panel: Color(0xFF09090B),
    border: Color(0xFF27272A),
    muted: Color(0xFFA1A1AA),
    text: Color(0xFFF4F4F5),
    secondary: Color(0xFFA1A1AA),
    accent: Color(0xFF22D3EE),
    onAccent: Color(0xFF042F2E),
    bubble: Color(0xFF18181B),
  );

  @override
  MailDeskColors copyWith({
    Color? bg,
    Color? panel,
    Color? border,
    Color? muted,
    Color? text,
    Color? secondary,
    Color? accent,
    Color? onAccent,
    Color? bubble,
  }) {
    return MailDeskColors(
      bg: bg ?? this.bg,
      panel: panel ?? this.panel,
      border: border ?? this.border,
      muted: muted ?? this.muted,
      text: text ?? this.text,
      secondary: secondary ?? this.secondary,
      accent: accent ?? this.accent,
      onAccent: onAccent ?? this.onAccent,
      bubble: bubble ?? this.bubble,
    );
  }

  @override
  MailDeskColors lerp(MailDeskColors? other, double t) => this;
}

class MailDeskTheme {
  static ThemeData light() => _theme(Brightness.light, MailDeskColors.light);

  static ThemeData dark() => _theme(Brightness.dark, MailDeskColors.dark);

  static ThemeData _theme(Brightness brightness, MailDeskColors colors) {
    final scheme = ColorScheme.fromSeed(
      seedColor: colors.accent,
      brightness: brightness,
      surface: colors.panel,
    ).copyWith(
      primary: colors.accent,
      onPrimary: colors.onAccent,
      surface: colors.bg,
      inverseSurface: brightness == Brightness.dark ? const Color(0xFF18181B) : colors.text,
      onInverseSurface: brightness == Brightness.dark ? colors.text : colors.panel,
    );

    final base = ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: colors.bg,
      dividerColor: colors.border,
      splashFactory: InkSparkle.splashFactory,
      visualDensity: VisualDensity.standard,
    );
    final text = base.textTheme.apply(
      bodyColor: colors.text,
      displayColor: colors.text,
      fontFamily: base.textTheme.bodyMedium?.fontFamily,
    ).copyWith(
      titleLarge: TextStyle(color: colors.text, fontSize: 20, fontWeight: FontWeight.w500, letterSpacing: -0.4, height: 1.2),
      titleMedium: TextStyle(color: colors.text, fontSize: 15, fontWeight: FontWeight.w500, letterSpacing: -0.2, height: 1.25),
      titleSmall: TextStyle(color: colors.text, fontSize: 13, fontWeight: FontWeight.w500, letterSpacing: -0.1),
      bodyLarge: TextStyle(color: colors.text, fontSize: 15, fontWeight: FontWeight.w400, height: 1.4),
      bodyMedium: TextStyle(color: colors.text, fontSize: 14, fontWeight: FontWeight.w400, height: 1.4),
      bodySmall: TextStyle(color: colors.muted, fontSize: 12, fontWeight: FontWeight.w400, height: 1.35),
      labelLarge: TextStyle(color: colors.text, fontSize: 14, fontWeight: FontWeight.w500, letterSpacing: 0),
      labelMedium: TextStyle(color: colors.secondary, fontSize: 12, fontWeight: FontWeight.w500),
      labelSmall: TextStyle(color: colors.muted, fontSize: 11, fontWeight: FontWeight.w500),
    );

    final fieldBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: BorderSide(color: colors.border),
    );

    return base.copyWith(
      textTheme: text,
      primaryTextTheme: text,
      iconTheme: IconThemeData(color: colors.secondary, size: 20),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: PredictiveBackPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: colors.bg,
        foregroundColor: colors.text,
        elevation: 0,
        scrolledUnderElevation: 0,
        surfaceTintColor: Colors.transparent,
        centerTitle: false,
        titleSpacing: 20,
        toolbarHeight: 60,
        iconTheme: IconThemeData(color: colors.secondary, size: 20),
        actionsIconTheme: IconThemeData(color: colors.secondary, size: 20),
        titleTextStyle: text.titleLarge,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: colors.panel,
        height: 62,
        elevation: 0,
        indicatorColor: colors.accent.withValues(alpha: 0.1),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w500,
            color: selected ? colors.accent : colors.muted,
          );
        }),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(color: selected ? colors.accent : colors.muted, size: 22);
        }),
      ),
      listTileTheme: ListTileThemeData(
        iconColor: colors.secondary,
        titleTextStyle: text.titleMedium,
        subtitleTextStyle: text.bodySmall,
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 2),
      ),
      dividerTheme: DividerThemeData(color: colors.border, thickness: 0.6, space: 0.6),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(foregroundColor: colors.secondary, iconSize: 20),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: brightness == Brightness.dark ? const Color(0xFF111113) : colors.panel,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        hintStyle: text.bodyMedium?.copyWith(color: colors.muted),
        labelStyle: text.bodyMedium?.copyWith(color: colors.muted),
        floatingLabelStyle: text.bodySmall?.copyWith(color: colors.accent, fontWeight: FontWeight.w500),
        prefixIconColor: colors.muted,
        suffixIconColor: colors.muted,
        border: fieldBorder,
        enabledBorder: fieldBorder,
        focusedBorder: fieldBorder.copyWith(borderSide: BorderSide(color: colors.accent)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: colors.accent,
          foregroundColor: colors.onAccent,
          minimumSize: const Size.fromHeight(46),
          elevation: 0,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w500),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: colors.accent,
          textStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: colors.panel,
        modalBackgroundColor: colors.panel,
        surfaceTintColor: Colors.transparent,
        dragHandleColor: colors.border,
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        elevation: 0,
        backgroundColor: brightness == Brightness.dark ? const Color(0xFF18181B) : colors.text,
        contentTextStyle: TextStyle(color: brightness == Brightness.dark ? colors.text : colors.panel, fontSize: 14, fontWeight: FontWeight.w500),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: colors.border),
        ),
        insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: colors.secondary,
          side: BorderSide(color: colors.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
        ),
      ),
      extensions: [colors],
    );
  }
}

MailDeskColors deskColors(BuildContext context) {
  return Theme.of(context).extension<MailDeskColors>() ?? MailDeskColors.light;
}
