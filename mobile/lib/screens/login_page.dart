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
              child: AutofillGroup(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Center(child: Image(image: AssetImage('assets/brand/icon.png'), width: 84, height: 84)),
                    const SizedBox(height: 20),
                    Text(
                      'MailDesk',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 32, fontWeight: FontWeight.w600, color: colors.text, letterSpacing: -0.8, height: 1.1),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Sign in to your workspace',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: colors.muted, fontSize: 15),
                    ),
                    const SizedBox(height: 32),
                    _caption(colors, 'Email'),
                    _field(
                      colors,
                      controller: email,
                      hint: 'you@company.com',
                      icon: LucideIcons.mail,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.username],
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 14),
                    _caption(colors, 'Password'),
                    _field(
                      colors,
                      controller: password,
                      hint: 'Password',
                      icon: LucideIcons.lock,
                      obscure: obscure,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.next,
                      onSubmitted: (_) => _submit(session),
                      suffix: IconButton(
                        tooltip: obscure ? 'Show password' : 'Hide password',
                        onPressed: () => setState(() => obscure = !obscure),
                        icon: Icon(obscure ? LucideIcons.eye : LucideIcons.eyeOff, size: 18),
                      ),
                    ),
                    const SizedBox(height: 14),
                    _caption(colors, 'Server'),
                    _field(
                      colors,
                      controller: server,
                      hint: 'https://maildesk.ng',
                      icon: LucideIcons.globe,
                      keyboardType: TextInputType.url,
                      autocorrect: false,
                      textInputAction: TextInputAction.done,
                      onSubmitted: (_) => _submit(session),
                    ),
                    if (session.error != null) ...[
                      const SizedBox(height: 14),
                      _ErrorNote(message: session.error!),
                    ],
                    const SizedBox(height: 22),
                    FilledButton(
                      onPressed: session.busy ? null : () => _submit(session),
                      child: session.busy
                          ? SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: colors.onAccent),
                            )
                          : const Text('Sign in'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _caption(MailDeskColors colors, String label) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 6),
      child: Text(label, style: TextStyle(color: colors.secondary, fontSize: 13, fontWeight: FontWeight.w500)),
    );
  }

  Widget _field(
    MailDeskColors colors, {
    required TextEditingController controller,
    required String hint,
    required IconData icon,
    TextInputType? keyboardType,
    Iterable<String>? autofillHints,
    TextInputAction? textInputAction,
    bool obscure = false,
    bool autocorrect = true,
    Widget? suffix,
    ValueChanged<String>? onSubmitted,
  }) {
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: BorderSide(color: colors.border),
    );
    return TextField(
      controller: controller,
      keyboardType: keyboardType,
      autofillHints: autofillHints,
      textInputAction: textInputAction,
      obscureText: obscure,
      autocorrect: autocorrect,
      onSubmitted: onSubmitted,
      style: TextStyle(color: colors.text, fontSize: 16),
      cursorColor: colors.accent,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: Icon(icon, size: 18, color: colors.muted),
        suffixIcon: suffix,
        filled: true,
        fillColor: colors.bubble,
        isDense: true,
        floatingLabelBehavior: FloatingLabelBehavior.never,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: BorderSide(color: colors.accent)),
      ),
    );
  }

  void _submit(Session session) {
    server.text = normalizeServerUrl(server.text);
    session.signIn(email: email.text, password: password.text, server: server.text);
  }
}

class _ErrorNote extends StatelessWidget {
  const _ErrorNote({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final color = Theme.of(context).colorScheme.error;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(LucideIcons.alertCircle, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(child: Text(message, style: TextStyle(color: color, fontSize: 13, height: 1.35))),
        ],
      ),
    );
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
      backgroundColor: colors.bg,
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
          children: [
            Text('Choose a workspace', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w600, color: colors.text, letterSpacing: -0.6)),
            const SizedBox(height: 6),
            Text('Signed in as ${session.user?.email ?? 'your account'}', style: TextStyle(color: colors.muted, fontSize: 14)),
            if (sessionError != null) ...[
              const SizedBox(height: 16),
              _ErrorNote(message: sessionError!),
            ],
            const SizedBox(height: 20),
            for (final workspace in session.workspaces) ...[
              Material(
                color: colors.panel,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14),
                  side: BorderSide(color: colors.border),
                ),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: () => session.chooseWorkspace(workspace),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                    child: Row(
                      children: [
                        CircleAvatar(
                          backgroundColor: colors.accent.withValues(alpha: 0.14),
                          foregroundColor: colors.accent,
                          child: Text(initials(workspace.name), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(workspace.name, style: TextStyle(color: colors.text, fontWeight: FontWeight.w500, fontSize: 15)),
                              if (workspace.subdomain != null)
                                Text('${workspace.subdomain}.maildesk.ng', style: TextStyle(color: colors.muted, fontSize: 12)),
                            ],
                          ),
                        ),
                        Icon(LucideIcons.chevronRight, size: 16, color: colors.muted),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],
          ],
        ),
      ),
    );
  }
}
