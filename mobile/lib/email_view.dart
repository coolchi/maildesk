import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:maildesk/quotes.dart';
import 'package:maildesk/theme.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

final _blankTarget = RegExp(r'''\s+target\s*=\s*(?:"[^"]*"|'[^']*')''', caseSensitive: false);

/// Render a sanitized email the same way the web inbox does: a white letter,
/// images and links intact, scaled to the screen.
class EmailView extends StatefulWidget {
  const EmailView({super.key, required this.html});

  final String html;

  @override
  State<EmailView> createState() => _EmailViewState();
}

class _EmailViewState extends State<EmailView> {
  static const _baseUrl = 'https://maildesk.invalid/';

  late final WebViewController _controller;
  var _height = 1.0;
  var _expanded = false;

  QuotedMessage get _split => splitQuotedHtml(widget.html.replaceAll(_blankTarget, ''));

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageFinished: (_) => _fit(),
          onNavigationRequest: _navigate,
        ),
      );
    if (defaultTargetPlatform != TargetPlatform.macOS) {
      _controller
        ..setBackgroundColor(Colors.white)
        ..setVerticalScrollBarEnabled(false)
        ..setHorizontalScrollBarEnabled(false);
    }
    _load();
  }

  void _load() {
    _controller.loadHtmlString(_srcdoc(), baseUrl: _baseUrl);
  }

  NavigationDecision _navigate(NavigationRequest request) {
    final url = request.url;
    if (url == _baseUrl || url.startsWith('about:') || url.startsWith('data:')) {
      return NavigationDecision.navigate;
    }
    final uri = Uri.tryParse(url);
    if (uri != null && uri.hasScheme) {
      launchUrl(uri, mode: LaunchMode.externalApplication);
    }
    return NavigationDecision.prevent;
  }

  Future<void> _fit() async {
    const script = '''
(function () {
  var target = document.getElementById('md-fit');
  if (!target) return 160;
  target.style.zoom = '1';
  var available = Math.max(40, document.documentElement.clientWidth);
  var contentWidth = target.scrollWidth || 0;
  if (contentWidth > available + 1) {
    var scale = Math.max(0.3, Math.min(1, available / contentWidth));
    target.style.zoom = String(Math.round(scale * 1000) / 1000);
  }
  document.documentElement.style.overflow = 'hidden';
  document.body.style.overflow = 'hidden';
  return Math.ceil((target.getBoundingClientRect().height || 0) + 28);
})()
''';
    for (final wait in const [Duration.zero, Duration(milliseconds: 350), Duration(milliseconds: 900)]) {
      if (wait != Duration.zero) {
        await Future<void>.delayed(wait);
      }
      if (!mounted) {
        return;
      }
      try {
        final raw = await _controller.runJavaScriptReturningResult(script);
        final height = double.tryParse(raw.toString().replaceAll('"', ''));
        if (height != null && height >= 1 && mounted && (height - _height).abs() > 1) {
          setState(() => _height = height + 4);
        }
      } catch (_) {
        if (mounted && _height < 8) {
          setState(() => _height = 120);
        }
      }
    }
  }

  String _srcdoc() {
    final split = _split;
    final body = (!split.hasQuote || _expanded) ? split.visible + split.quoted : split.visible;
    return '''
<!doctype html><html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<meta http-equiv="Content-Security-Policy" content="script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'">
<style>
:root { color-scheme: light only; }
html, body { background:#ffffff !important; color:#111827; margin:0; height:auto; }
body { padding:12px 14px 16px; font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; overflow-wrap:anywhere; }
img, video, svg { max-width:100% !important; height:auto !important; }
table { max-width:100% !important; }
pre, code { white-space:pre-wrap; word-break:break-word; }
a { word-break:break-all; }
</style>
</head><body><div id="md-fit">$body</div></body></html>
''';
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: ColoredBox(
            color: Colors.white,
            child: SizedBox(
              height: _height,
              child: WebViewWidget(controller: _controller),
            ),
          ),
        ),
        if (_split.hasQuote)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              style: TextButton.styleFrom(
                visualDensity: VisualDensity.compact,
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                minimumSize: Size.zero,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              ),
              onPressed: () {
                setState(() {
                  _expanded = !_expanded;
                  _height = 1;
                });
                _load();
              },
              child: Text(_expanded ? 'Hide quoted text' : 'Show quoted text', style: TextStyle(color: colors.accent)),
            ),
          ),
      ],
    );
  }
}
