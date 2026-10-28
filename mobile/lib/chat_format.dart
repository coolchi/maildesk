import 'package:flutter/material.dart';

class ChatRun {
  const ChatRun(
    this.text, {
    this.bold = false,
    this.italic = false,
    this.strike = false,
    this.code = false,
  });

  final String text;
  final bool bold;
  final bool italic;
  final bool strike;
  final bool code;
}

/// WhatsApp-style marks: *bold*, **bold**, _italic_, ~strike~, `code`, ```code```.
final RegExp chatMarks = RegExp(
  '(?:^|(?<=\\s))(?:'
  '```([^\\s`][\\s\\S]*?[^\\s`]|[^\\s`])```'
  '|\\*\\*([^\\s*][\\s\\S]*?[^\\s*]|[^\\s*])\\*\\*'
  '|\\*([^\\s*][\\s\\S]*?[^\\s*]|[^\\s*])\\*'
  '|_([^\\s_][\\s\\S]*?[^\\s_]|[^\\s_])_'
  '|~([^\\s~][\\s\\S]*?[^\\s~]|[^\\s~])~'
  '|`([^\\s`][^`]*?[^\\s`]|[^\\s`])`'
  ')(?=\$|\\s|[.,!?;:)\\]"\'])',
);

List<ChatRun> parseChatFormat(
  String input, {
  bool bold = false,
  bool italic = false,
  bool strike = false,
  bool code = false,
}) {
  if (input.isEmpty) {
    return const [];
  }
  if (code) {
    return [ChatRun(input, bold: bold, italic: italic, strike: strike, code: true)];
  }

  final runs = <ChatRun>[];
  var index = 0;
  for (final match in chatMarks.allMatches(input)) {
    if (match.start > index) {
      runs.add(ChatRun(input.substring(index, match.start), bold: bold, italic: italic, strike: strike));
    }
    final piece = match.group(1) ?? match.group(2) ?? match.group(3) ?? match.group(4) ?? match.group(5) ?? match.group(6)!;
    if (match.group(1) != null || match.group(6) != null) {
      runs.add(ChatRun(piece, bold: bold, italic: italic, strike: strike, code: true));
    } else if (match.group(2) != null || match.group(3) != null) {
      runs.addAll(parseChatFormat(piece, bold: true, italic: italic, strike: strike));
    } else if (match.group(4) != null) {
      runs.addAll(parseChatFormat(piece, bold: bold, italic: true, strike: strike));
    } else {
      runs.addAll(parseChatFormat(piece, bold: bold, italic: italic, strike: true));
    }
    index = match.end;
  }
  if (index < input.length) {
    runs.add(ChatRun(input.substring(index), bold: bold, italic: italic, strike: strike));
  }
  return runs;
}

String stripChatFormat(String text) => parseChatFormat(text).map((run) => run.text).join();

class ChatText extends StatelessWidget {
  const ChatText(
    this.text, {
    super.key,
    required this.style,
    this.maxLines,
    this.overflow,
  });

  final String text;
  final TextStyle style;
  final int? maxLines;
  final TextOverflow? overflow;

  @override
  Widget build(BuildContext context) {
    return Text.rich(
      TextSpan(
        children: [
          for (final run in parseChatFormat(text))
            TextSpan(text: run.text, style: _style(style, run)),
        ],
      ),
      maxLines: maxLines,
      overflow: overflow,
    );
  }

  TextStyle _style(TextStyle base, ChatRun run) {
    return base.copyWith(
      fontWeight: run.bold ? FontWeight.w700 : base.fontWeight,
      fontStyle: run.italic ? FontStyle.italic : base.fontStyle,
      decoration: run.strike ? TextDecoration.lineThrough : base.decoration,
      decorationColor: run.strike ? base.color : base.decorationColor,
      fontFamily: run.code ? 'monospace' : base.fontFamily,
      backgroundColor: run.code ? base.color?.withValues(alpha: 0.14) : null,
    );
  }
}
