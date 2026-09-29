import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/quotes.dart';

void main() {
  test('quoted reply history stays behind the new message', () {
    const body = '''again

On Sat, Sep 26, 2026 at 10:58 AM sherif coolchi <coolchi01@gmail.com> wrote:

> gaian
> Ok thank again''';

    final split = splitQuotedText(body);

    expect(split.hasQuote, isTrue);
    expect(split.visible, 'again');
    expect(split.quoted, startsWith('On Sat, Sep 26, 2026'));
  });

  test('formatted mail keeps the html letter', () {
    final message = MailMessage.fromJson({
      'from': 'noreply@supabase.com',
      'text': '[image: Supabase] These issues require your attention',
      'html': '<h2 style="color:red">These issues require your immediate attention</h2>',
      'sent': '2 days ago',
    });

    expect(message.html, contains('immediate attention'));
    expect(message.body, contains('[image: Supabase]'));
  });

  test('a letter that is only a quote stays visible', () {
    const html = '<div class="gmail_quote">Forwarded message from Supabase</div>';

    final split = splitQuotedHtml(html);

    expect(split.hasQuote, isFalse);
    expect(split.visible, html);
  });

  test('reply history is removed so the box can shrink', () {
    const html = '<p>Check</p><div class="gmail_quote">On Sat someone wrote: older mail</div>';

    final split = splitQuotedHtml(html);

    expect(split.hasQuote, isTrue);
    expect(split.visible, '<p>Check</p>');
    expect(split.quoted, contains('older mail'));
  });

  test('a message without reply history stays intact', () {
    const body = 'Hi there, Write your message here...\n\n--\nAde Okafor';

    final split = splitQuotedText(body);

    expect(split.hasQuote, isFalse);
    expect(split.visible, body);
  });
}
