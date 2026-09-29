import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/session.dart';
import 'package:maildesk/theme.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final email = TextEditingController();
  final password = TextEditingController();
  final server = TextEditingController(text: 'https://maildesk.ng');
  var obscure = true;
  var seeded = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!seeded) {
      seeded = true;
      server.text = Desk.of(context).baseUrl;
    }
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    server.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    if (session.token != null && session.workspace == null) {
      return _WorkspacePicker(sessionError: session.error);
    }

    return Scaffold(
      backgroundColor: colors.bg,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Image.asset('assets/brand/icon.png', width: 72, height: 72),
                  ),
                  const SizedBox(height: 20),
                  Text('MailDesk', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w500, color: colors.text, letterSpacing: -0.6)),
                  const SizedBox(height: 6),
                  Text('Sign in to your workspace', style: TextStyle(color: colors.muted, fontSize: 14, fontWeight: FontWeight.w400)),
                  const SizedBox(height: 28),
                  TextField(
                    controller: email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.username],
                    textInputAction: TextInputAction.next,
                    decoration: const InputDecoration(labelText: 'Email'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: password,
                    obscureText: obscure,
                    autofillHints: const [AutofillHints.password],
                    onSubmitted: (_) => _submit(session),
                    decoration: InputDecoration(
                      labelText: 'Password',
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => obscure = !obscure),
                        icon: Icon(obscure ? LucideIcons.eye : LucideIcons.eyeOff),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: server,
                    keyboardType: TextInputType.url,
                    autocorrect: false,
                    decoration: const InputDecoration(
                      labelText: 'Server',
                      hintText: 'https://maildesk.ng',
                    ),
                  ),
                  if (session.error != null) ...[
                    const SizedBox(height: 12),
                    Text(session.error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: session.busy ? null : () => _submit(session),
                    child: session.busy
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Text('Sign in'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  void _submit(Session session) {
    session.signIn(email: email.text, password: password.text, server: server.text);
  }
}

class _WorkspacePicker extends StatelessWidget {
  const _WorkspacePicker({required this.sessionError});

  final String? sessionError;

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Workspace')),
      body: ListView(
        children: [
          if (sessionError != null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(sessionError!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
          for (final workspace in session.workspaces)
            ListTile(
              leading: CircleAvatar(
                backgroundColor: colors.accent.withValues(alpha: 0.12),
                foregroundColor: colors.accent,
                child: Text(initials(workspace.name)),
              ),
              title: Text(workspace.name),
              subtitle: Text(workspace.subdomain == null ? '' : '${workspace.subdomain}.maildesk.ng'),
              onTap: () => session.chooseWorkspace(workspace),
            ),
        ],
      ),
    );
  }
}
