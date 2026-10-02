import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:maildesk/screens/home_shell.dart';
import 'package:maildesk/screens/login_page.dart';
import 'package:maildesk/session.dart';
import 'package:maildesk/theme.dart';

@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {}
}

class Desk extends InheritedNotifier<Session> {
  const Desk({super.key, required Session session, required super.child}) : super(notifier: session);

  static Session of(BuildContext context) {
    final desk = context.dependOnInheritedWidgetOfExactType<Desk>();
    return desk!.notifier!;
  }

  static Session read(BuildContext context) {
    final element = context.getElementForInheritedWidgetOfExactType<Desk>();
    return (element!.widget as Desk).notifier!;
  }
}

class MailDeskApp extends StatefulWidget {
  const MailDeskApp({super.key});

  @override
  State<MailDeskApp> createState() => _MailDeskAppState();
}

class _MailDeskAppState extends State<MailDeskApp> {
  final session = Session();

  @override
  void initState() {
    super.initState();
    session.restore();
  }

  @override
  void dispose() {
    session.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Desk(
      session: session,
      child: ListenableBuilder(
        listenable: session,
        builder: (context, _) {
          final signedIn = session.token != null && session.workspace != null;
          return MaterialApp(
            title: 'MailDesk',
            debugShowCheckedModeBanner: false,
            theme: MailDeskTheme.light(),
            darkTheme: MailDeskTheme.dark(),
            themeMode: switch (session.theme) {
              'light' => ThemeMode.light,
              'dark' => ThemeMode.dark,
              _ => ThemeMode.system,
            },
            home: !session.ready
                ? const _Splash()
                : signedIn
                    ? const HomeShell()
                    : const LoginPage(),
          );
        },
      ),
    );
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  try {
    await Firebase.initializeApp();
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);
  } catch (_) {}
  runApp(const MailDeskApp());
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return const ColoredBox(
      color: Color(0xFF000000),
      child: Center(
        child: Image(image: AssetImage('assets/brand/icon.png'), width: 108, height: 108),
      ),
    );
  }
}
