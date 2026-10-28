import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/session.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('sign in screen uses the MailDesk name', (tester) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(const MailDeskApp());
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('MailDesk'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
  });

  test('a public server typed with http is reached over https', () {
    expect(normalizeServerUrl('http://maildesk.ng'), 'https://maildesk.ng');
    expect(normalizeServerUrl('http://maildesk.ng/'), 'https://maildesk.ng');
    expect(normalizeServerUrl('https://maildesk.ng'), 'https://maildesk.ng');
    expect(normalizeServerUrl('http://maildesk.test'), 'http://maildesk.test');
    expect(normalizeServerUrl('http://127.0.0.1:8000'), 'http://127.0.0.1:8000');
  });

  test('appearance choice is remembered', () async {
    SharedPreferences.setMockInitialValues({});
    final session = Session();
    await session.setTheme('light');

    final restored = Session();
    await restored.restore();

    expect(restored.theme, 'light');
  });
}
