class QuotedMessage {
  const QuotedMessage({required this.visible, required this.quoted});

  final String visible;
  final String quoted;

  bool get hasQuote => quoted.trim().isNotEmpty;
}

final _plainQuote = RegExp(
  r'(?:^|\n)(?:On .{10,200} wrote:|-{2,}\s*Original Message\s*-{2,}|_{5,}|From:\s.+\nSent:\s)',
  caseSensitive: false,
);

/// Keep the new message and tuck reply history behind a toggle.
QuotedMessage splitQuotedText(String text) {
  if (text.trim().isEmpty) {
    return QuotedMessage(visible: text, quoted: '');
  }

  final match = _plainQuote.firstMatch(text);
  if (match == null) {
    return QuotedMessage(visible: text, quoted: '');
  }

  final marker = match.group(0)!;
  var at = match.start;
  if (marker.startsWith('\n')) {
    at += 1;
  }
  if (at <= 0) {
    return QuotedMessage(visible: text, quoted: '');
  }

  final visible = text.substring(0, at).trimRight();
  final quoted = text.substring(at).trimLeft();
  if (visible.trim().isEmpty || quoted.trim().isEmpty) {
    return QuotedMessage(visible: text, quoted: '');
  }

  return QuotedMessage(visible: visible, quoted: quoted);
}

final _quoteTag = RegExp(
  'class\\s*=\\s*["\'][^"\']*(?:gmail_quote|gmail_extra|yahoo_quoted|protonmail_quote|moz-cite-prefix)[^"\']*["\']|id\\s*=\\s*["\']divRplyFwdMsg["\']|type\\s*=\\s*["\']cite["\']',
  caseSensitive: false,
);

final _tags = RegExp(r'<[^>]+>');

/// Drop reply history only when the new message still has text of its own.
QuotedMessage splitQuotedHtml(String html) {
  final match = _quoteTag.firstMatch(html);
  final start = match == null ? -1 : html.lastIndexOf('<', match.start);
  if (match == null || start <= 0) {
    return QuotedMessage(visible: html, quoted: '');
  }

  final visible = html.substring(0, start).trimRight();
  final quoted = html.substring(start).trimLeft();
  if (_readable(visible).isEmpty || _readable(quoted).isEmpty) {
    return QuotedMessage(visible: html, quoted: '');
  }

  return QuotedMessage(visible: visible, quoted: quoted);
}

String _readable(String html) {
  return html.replaceAll(_tags, ' ').replaceAll('&nbsp;', ' ').replaceAll(RegExp(r'\s+'), ' ').trim();
}
