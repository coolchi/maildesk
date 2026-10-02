import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/attachments.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/outbox.dart';
import 'package:maildesk/theme.dart';

class ComposePage extends StatefulWidget {
  const ComposePage({super.key, this.draft});

  final MailDraft? draft;

  @override
  State<ComposePage> createState() => _ComposePageState();
}

class _ComposePageState extends State<ComposePage> {
  final to = TextEditingController();
  final cc = TextEditingController();
  final bcc = TextEditingController();
  final ccFocus = FocusNode();
  final bccFocus = FocusNode();
  final subject = TextEditingController();
  final body = TextEditingController();
  final files = <LocalAttachment>[];
  var showCc = false;
  var showBcc = false;
  var sending = false;
  var assisting = false;
  AiCapabilities ai = const AiCapabilities();
  String? error;

  @override
  void initState() {
    super.initState();
    final draft = widget.draft;
    if (draft != null) {
      to.text = draft.to ?? '';
      cc.text = draft.cc ?? '';
      bcc.text = draft.bcc ?? '';
      subject.text = draft.subject;
      body.text = draft.body;
      showCc = cc.text.trim().isNotEmpty;
      showBcc = bcc.text.trim().isNotEmpty;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadAi());
  }

  @override
  void dispose() {
    to.dispose();
    cc.dispose();
    bcc.dispose();
    ccFocus.dispose();
    bccFocus.dispose();
    subject.dispose();
    body.dispose();
    super.dispose();
  }

  String? _extra(TextEditingController controller) {
    final value = controller.text.trim();
    return value.isEmpty ? null : value;
  }

  void _hideCc() {
    setState(() {
      showCc = false;
      cc.clear();
    });
  }

  void _hideBcc() {
    setState(() {
      showBcc = false;
      bcc.clear();
    });
  }

  void _reveal({bool cc = false, bool bcc = false}) {
    setState(() {
      if (cc) {
        showCc = true;
      }
      if (bcc) {
        showBcc = true;
      }
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (cc) {
        ccFocus.requestFocus();
      }
      if (bcc) {
        bccFocus.requestFocus();
      }
    });
  }

  Future<void> _loadAi() async {
    try {
      final flags = await Desk.read(context).api.ai();
      if (mounted) {
        setState(() => ai = flags);
      }
    } on ApiException {
      return;
    }
  }

  Future<void> _pick() async {
    final picked = await pickAttachments();
    if (picked.isEmpty || !mounted) {
      return;
    }
    setState(() {
      files.addAll(picked);
      if (files.length > 10) {
        files.removeRange(10, files.length);
        error = 'You can attach up to 10 files.';
      }
    });
  }

  Future<void> _assist(String action, {String? tone}) async {
    setState(() {
      assisting = true;
      error = null;
    });
    try {
      final result = await Desk.of(context).api.composeAssist(
            action: action,
            body: body.text.trim(),
            subject: subject.text.trim(),
            tone: tone,
          );
      if (!mounted) {
        return;
      }
      if (result.subjects.length > 1) {
        final chosen = await showModalBottomSheet<String>(
          context: context,
          showDragHandle: true,
          builder: (context) => SafeArea(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final line in result.subjects)
                  ListTile(title: Text(line), onTap: () => Navigator.pop(context, line)),
              ],
            ),
          ),
        );
        if (chosen != null) {
          subject.text = chosen;
        }
      } else if (result.subject != null && result.subject!.isNotEmpty) {
        subject.text = result.subject!;
      }
      if (result.text != null && result.text!.isNotEmpty) {
        body.text = result.text!;
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    } finally {
      if (mounted) {
        setState(() => assisting = false);
      }
    }
  }

  Future<void> _send() async {
    if (to.text.trim().isEmpty || subject.text.trim().isEmpty || body.text.trim().isEmpty) {
      setState(() => error = 'Add a recipient, subject, and message.');
      return;
    }
    setState(() {
      sending = true;
      error = null;
    });
    try {
      final result = await Desk.of(context).composeMail(
            to: to.text.trim(),
            cc: _extra(cc),
            bcc: _extra(bcc),
            subject: subject.text.trim(),
            body: body.text.trim(),
            files: [for (final file in files) file.path],
            draftId: (widget.draft?.id != null && widget.draft!.id > 0) ? widget.draft!.id : null,
          );
      if (!mounted) {
        return;
      }
      if (result == OutboxResult.queued) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Saved. We’ll send it when you’re back online.')),
        );
      }
      Navigator.of(context).pop(true);
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() {
          sending = false;
          error = exception.message;
        });
      }
    }
  }

  Future<void> _draft() async {
    try {
      final result = await Desk.of(context).saveMailDraft(
            id: (widget.draft?.id != null && widget.draft!.id > 0) ? widget.draft!.id : null,
            to: to.text.trim(),
            cc: _extra(cc),
            bcc: _extra(bcc),
            subject: subject.text.trim(),
            body: body.text.trim(),
          );
      if (!mounted) {
        return;
      }
      if (result == OutboxResult.queued) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Draft saved on this phone. We’ll sync it when you’re back online.')),
        );
      }
      Navigator.of(context).pop(false);
    } on ApiException catch (exception) {
      setState(() => error = exception.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final session = Desk.of(context);
    final from = session.user?.email ?? session.workspace?.name ?? 'MailDesk';
    final field = InputDecoration(
      filled: false,
      isDense: true,
      border: InputBorder.none,
      enabledBorder: InputBorder.none,
      focusedBorder: InputBorder.none,
      contentPadding: const EdgeInsets.symmetric(vertical: 14),
      hintStyle: TextStyle(color: colors.muted, fontWeight: FontWeight.w400, fontSize: 15),
    );
    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(title: const Text('Compose')),
      body: Column(
        children: [
          Expanded(
            child: Container(
              margin: const EdgeInsets.fromLTRB(16, 4, 16, 8),
              decoration: BoxDecoration(
                color: colors.panel,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: colors.border),
              ),
              child: Column(
                children: [
                  _ComposeLine(
                    label: 'From',
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      child: Text(from, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.secondary, fontSize: 15)),
                    ),
                  ),
                  Divider(height: 1, color: colors.border),
                  _ComposeLine(
                    label: 'To',
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (!showCc) _RecipientLink(label: 'Cc', onPressed: () => _reveal(cc: true)),
                        if (!showBcc) _RecipientLink(label: 'Bcc', onPressed: () => _reveal(bcc: true)),
                      ],
                    ),
                    child: TextField(
                      controller: to,
                      keyboardType: TextInputType.emailAddress,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w400),
                      decoration: field.copyWith(hintText: 'name@example.com'),
                    ),
                  ),
                  if (showCc) ...[
                    Divider(height: 1, color: colors.border),
                    _ComposeLine(
                      label: 'Cc',
                      trailing: _FieldClose(onPressed: _hideCc),
                      child: TextField(
                        controller: cc,
                        focusNode: ccFocus,
                        keyboardType: TextInputType.emailAddress,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w400),
                        decoration: field.copyWith(hintText: 'name@example.com'),
                      ),
                    ),
                  ],
                  if (showBcc) ...[
                    Divider(height: 1, color: colors.border),
                    _ComposeLine(
                      label: 'Bcc',
                      trailing: _FieldClose(onPressed: _hideBcc),
                      child: TextField(
                        controller: bcc,
                        focusNode: bccFocus,
                        keyboardType: TextInputType.emailAddress,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w400),
                        decoration: field.copyWith(hintText: 'name@example.com'),
                      ),
                    ),
                  ],
                  Divider(height: 1, color: colors.border),
                  _ComposeLine(
                    label: 'Subject',
                    child: TextField(
                      controller: subject,
                      textCapitalization: TextCapitalization.sentences,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w400),
                      decoration: field.copyWith(hintText: 'What is this about?'),
                    ),
                  ),
                  Divider(height: 1, color: colors.border),
                  Expanded(
                    child: TextField(
                      controller: body,
                      expands: true,
                      maxLines: null,
                      textAlignVertical: TextAlignVertical.top,
                      textCapitalization: TextCapitalization.sentences,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w400, height: 1.45),
                      decoration: field.copyWith(
                        hintText: 'Write your message',
                        contentPadding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
                      ),
                    ),
                  ),
                  if (files.isNotEmpty) ...[
                    Divider(height: 1, color: colors.border),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: AttachmentTray(
                        files: files,
                        onRemove: (file) => setState(() => files.remove(file)),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 13)),
            ),
          Padding(
            padding: EdgeInsets.fromLTRB(16, 0, 16, 12 + MediaQuery.viewPaddingOf(context).bottom),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (ai.composeAssist)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: [
                          _ComposePill(icon: LucideIcons.sparkles, label: assisting ? 'Writing…' : 'Rewrite', onPressed: assisting ? null : () => _assist('rewrite')),
                          _ComposePill(icon: LucideIcons.minimize2, label: 'Shorter', onPressed: assisting ? null : () => _assist('shorten')),
                          _ComposePill(icon: LucideIcons.smile, label: 'Friendlier', onPressed: assisting ? null : () => _assist('tone', tone: 'friendly')),
                          _ComposePill(icon: LucideIcons.heading, label: 'Subjects', onPressed: assisting ? null : () => _assist('subject')),
                        ],
                      ),
                    ),
                  ),
                Row(
                  children: [
                    FilledButton.icon(
                      onPressed: sending ? null : _send,
                      style: FilledButton.styleFrom(
                        minimumSize: const Size(0, 36),
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                        shape: const StadiumBorder(),
                      ),
                      icon: sending
                          ? SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 1.6, color: colors.onAccent))
                          : const Icon(LucideIcons.send, size: 16),
                      label: Text(sending ? 'Sending…' : 'Send'),
                    ),
                    const Spacer(),
                    _ComposePill(icon: LucideIcons.fileEdit, label: 'Draft', onPressed: sending ? null : _draft),
                    _ComposePill(icon: LucideIcons.paperclip, label: files.isEmpty ? 'Attach' : 'Add', onPressed: _pick),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ComposeLine extends StatelessWidget {
  const _ComposeLine({required this.label, required this.child, this.trailing});

  final String label;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: [
          SizedBox(width: 72, child: Text(label, style: TextStyle(color: colors.muted, fontSize: 13))),
          Expanded(child: child),
          ?trailing,
        ],
      ),
    );
  }
}

class _FieldClose extends StatelessWidget {
  const _FieldClose({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return IconButton(
      tooltip: 'Close',
      visualDensity: VisualDensity.compact,
      onPressed: onPressed,
      icon: Icon(LucideIcons.x, size: 16, color: colors.muted),
    );
  }
}

class _RecipientLink extends StatelessWidget {
  const _RecipientLink({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return TextButton(
      onPressed: onPressed,
      style: TextButton.styleFrom(
        foregroundColor: colors.muted,
        minimumSize: Size.zero,
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
        textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
      ),
      child: Text(label),
    );
  }
}

class _ComposePill extends StatelessWidget {
  const _ComposePill({required this.icon, required this.label, this.onPressed});

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: OutlinedButton.icon(
        onPressed: onPressed,
        icon: Icon(icon, size: 15, color: colors.secondary),
        label: Text(label),
        style: OutlinedButton.styleFrom(
          foregroundColor: colors.secondary,
          side: BorderSide(color: colors.border),
          minimumSize: const Size(0, 34),
          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          visualDensity: VisualDensity.compact,
          shape: const StadiumBorder(),
          textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
        ),
      ),
    );
  }
}

Future<void> showAddContact(BuildContext context) async {
  final colors = deskColors(context);
  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    backgroundColor: colors.panel,
    builder: (context) => const _AddContactSheet(),
  );
  if (saved == true && context.mounted) {
    HapticFeedback.lightImpact();
    final tone = deskColors(context);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(LucideIcons.check, size: 16, color: tone.accent),
            const SizedBox(width: 8),
            const Text('Contact saved'),
          ],
        ),
      ),
    );
  }
}

class _AddContactSheet extends StatefulWidget {
  const _AddContactSheet();

  @override
  State<_AddContactSheet> createState() => _AddContactSheetState();
}

class _AddContactSheetState extends State<_AddContactSheet> {
  final email = TextEditingController();
  final name = TextEditingController();
  final company = TextEditingController();
  var saving = false;
  String? error;

  @override
  void dispose() {
    email.dispose();
    name.dispose();
    company.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      saving = true;
      error = null;
    });
    try {
      await Desk.of(context).api.addContact(
            email: email.text.trim(),
            name: name.text.trim(),
            company: company.text.trim(),
          );
      if (mounted) {
        Navigator.pop(context, true);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() {
          saving = false;
          error = exception.message;
        });
      }
    }
  }

  Widget _field(TextEditingController controller, String hint, {TextInputType? keyboard, TextCapitalization capitalization = TextCapitalization.none}) {
    final colors = deskColors(context);
    return TextField(
      controller: controller,
      keyboardType: keyboard,
      textCapitalization: capitalization,
      style: TextStyle(fontSize: 15, height: 1.2, color: colors.text),
      cursorColor: colors.accent,
      decoration: InputDecoration(
        hintText: hint,
        filled: true,
        isCollapsed: true,
        floatingLabelBehavior: FloatingLabelBehavior.never,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: colors.border)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: colors.border)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: colors.accent)),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 4, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Add contact', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600, color: colors.text)),
          const SizedBox(height: 2),
          Text('Save someone you write to.', style: TextStyle(color: colors.muted, fontSize: 13)),
          const SizedBox(height: 16),
          _field(email, 'Email', keyboard: TextInputType.emailAddress),
          const SizedBox(height: 8),
          _field(name, 'Name', capitalization: TextCapitalization.words),
          const SizedBox(height: 8),
          _field(company, 'Company', capitalization: TextCapitalization.words),
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: saving ? null : _save,
            child: const Text('Add contact'),
          ),
        ],
      ),
    );
  }
}
