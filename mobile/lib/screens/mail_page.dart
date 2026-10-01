import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/email_view.dart';
import 'package:maildesk/attachments.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/media.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/quotes.dart';
import 'package:maildesk/theme.dart';

class MailPage extends StatefulWidget {
  const MailPage({super.key, required this.thread});

  final MailThread thread;

  @override
  State<MailPage> createState() => _MailPageState();
}

class _MailPageState extends State<MailPage> {
  MailDetail? detail;
  String? error;
  final reply = TextEditingController();
  final forwardTo = TextEditingController();
  final files = <LocalAttachment>[];
  var sending = false;
  var assisting = false;
  var composer = _Composer.reply;
  AiCapabilities ai = const AiCapabilities();
  ThreadSummary? summary;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _load();
      _loadAi();
    });
  }

  @override
  void dispose() {
    reply.dispose();
    forwardTo.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final mail = await Desk.read(context).api.mail(widget.thread.id);
      if (mounted) {
        setState(() {
          detail = mail;
          error = null;
        });
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  Future<void> _confirmSpam() async {
    final colors = deskColors(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: colors.panel,
        title: Text('Mark as spam?', style: TextStyle(color: colors.text, fontSize: 18, fontWeight: FontWeight.w600)),
        content: Text('This conversation will move to Spam.', style: TextStyle(color: colors.secondary, height: 1.4)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Mark as spam')),
        ],
      ),
    );
    if (confirmed == true && mounted) {
      await _act('spam');
    }
  }

  Future<void> _markUnread() async {
    try {
      await Desk.of(context).api.mailAction(widget.thread.id, 'unread');
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  Future<void> _act(String action) async {
    try {
      await Desk.of(context).api.mailAction(widget.thread.id, action);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  Future<void> _sendReply() async {
    final body = reply.text.trim();
    if (body.isEmpty || sending) {
      return;
    }
    setState(() => sending = true);
    try {
      await Desk.of(context).api.reply(widget.thread.id, body, files: [for (final file in files) file.path]);
      reply.clear();
      files.clear();
      await _load();
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    } finally {
      if (mounted) {
        setState(() => sending = false);
      }
    }
  }

  Future<void> _sendForward() async {
    final to = forwardTo.text.trim();
    if (to.isEmpty || sending) {
      setState(() => error = 'Add the address to forward to.');
      return;
    }
    setState(() => sending = true);
    try {
      await Desk.of(context).api.forward(widget.thread.id, to: to, body: reply.text.trim());
      forwardTo.clear();
      reply.clear();
      files.clear();
      await _load();
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    } finally {
      if (mounted) {
        setState(() => sending = false);
      }
    }
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

  Future<void> _suggest() async {
    setState(() => assisting = true);
    try {
      reply.text = await Desk.of(context).api.suggestReply(widget.thread.id);
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

  Future<void> _summarize() async {
    setState(() => assisting = true);
    try {
      final result = await Desk.of(context).api.summarize(widget.thread.id);
      if (mounted) {
        setState(() => summary = result);
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

  String _replyAddress() {
    final fromThread = widget.thread.fromEmail;
    if (fromThread != null && fromThread.isNotEmpty) {
      return fromThread;
    }
    final messages = detail?.messages ?? [];
    if (messages.isEmpty) {
      return '';
    }
    return messages.last.from;
  }

  Future<void> _attach() async {
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

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final session = Desk.of(context);
    final mail = detail;
    final api = session.api;
    final subject = mail?.subject ?? widget.thread.subject;
    return Scaffold(
      body: Column(
        children: [
          _ThreadHeader(
            folder: session.inboxTitle,
            subject: subject,
            onBack: () => Navigator.of(context).pop(),
            onArchive: () => _act('archive'),
            onTrash: () => _act('trash'),
            onSpam: _confirmSpam,
            onUnread: _markUnread,
          ),
          Expanded(
            child: mail == null
                ? Center(
                    child: error == null
                        ? const CircularProgressIndicator()
                        : Padding(
                            padding: const EdgeInsets.all(32),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(LucideIcons.mailX, color: colors.muted, size: 28),
                                const SizedBox(height: 12),
                                Text(
                                  error!,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(color: colors.text, height: 1.4),
                                ),
                                const SizedBox(height: 8),
                                TextButton(
                                  onPressed: () => Navigator.of(context).pop(),
                                  child: const Text('Back'),
                                ),
                              ],
                            ),
                          ),
                  )
                : ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (summary != null)
                        Container(
                          margin: const EdgeInsets.only(bottom: 12),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: colors.accent.withValues(alpha: 0.08),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(summary!.summary, style: TextStyle(color: colors.text, height: 1.35)),
                              for (final item in summary!.actionItems)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Text('• $item', style: TextStyle(color: colors.secondary, fontSize: 13)),
                                ),
                            ],
                          ),
                        ),
                      for (final message in mail.messages)
                        Container(
                          margin: const EdgeInsets.only(bottom: 12),
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            color: colors.panel,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: colors.border),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(message.fromName ?? message.from, style: TextStyle(fontWeight: FontWeight.w500, fontSize: 14, color: colors.text)),
                                        if (message.fromName != null) Text(message.from, style: TextStyle(color: colors.muted, fontSize: 13)),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Text(message.sent, style: TextStyle(color: colors.muted, fontSize: 12)),
                                ],
                              ),
                              const SizedBox(height: 8),
                              if (message.html != null)
                                EmailView(html: message.html!)
                              else
                                _MessageBody(body: message.body),
                              for (final file in message.attachments) ...[
                                const SizedBox(height: 10),
                                _MailAttachment(api: api, file: file),
                              ],
                            ],
                          ),
                        ),
                      if (error != null) Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    ],
                  ),
          ),
          Container(
            padding: EdgeInsets.fromLTRB(12, 10, 12, 10 + MediaQuery.viewPaddingOf(context).bottom),
            decoration: BoxDecoration(color: colors.panel, border: Border(top: BorderSide(color: colors.border))),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      if (ai.threadSummary)
                        _ActionPill(
                          icon: LucideIcons.sparkles,
                          label: assisting && summary == null ? 'Summarizing…' : 'Summarize',
                          onPressed: assisting ? null : _summarize,
                        ),
                      _ActionPill(
                        icon: LucideIcons.reply,
                        label: 'Reply',
                        selected: composer == _Composer.reply,
                        onPressed: () => setState(() => composer = composer == _Composer.reply ? _Composer.closed : _Composer.reply),
                      ),
                      _ActionPill(
                        icon: LucideIcons.forward,
                        label: 'Forward',
                        selected: composer == _Composer.forward,
                        onPressed: () => setState(() => composer = composer == _Composer.forward ? _Composer.closed : _Composer.forward),
                      ),
                      _ActionPill(
                        icon: LucideIcons.shieldAlert,
                        label: 'Mark as Spam',
                        onPressed: _confirmSpam,
                      ),
                    ],
                  ),
                ),
                if (composer != _Composer.closed) ...[
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          composer == _Composer.forward
                              ? 'Forward “${widget.thread.subject}”'
                              : 'Reply to ${_replyAddress()}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: colors.muted, fontSize: 12),
                        ),
                      ),
                      IconButton(
                        tooltip: 'Close',
                        visualDensity: VisualDensity.compact,
                        onPressed: () => setState(() => composer = _Composer.closed),
                        icon: Icon(LucideIcons.x, size: 18, color: colors.muted),
                      ),
                    ],
                  ),
                  if (composer == _Composer.forward)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: TextField(
                        controller: forwardTo,
                        keyboardType: TextInputType.emailAddress,
                        decoration: const InputDecoration(hintText: 'To'),
                      ),
                    ),
                  TextField(
                    controller: reply,
                    minLines: 4,
                    maxLines: 8,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: InputDecoration(
                      hintText: composer == _Composer.forward ? 'Add a note (optional)…' : 'Write a reply…',
                    ),
                  ),
                  if (files.isNotEmpty) const SizedBox(height: 8),
                  AttachmentTray(
                    files: files,
                    onRemove: (file) => setState(() => files.remove(file)),
                  ),
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      FilledButton.icon(
                        onPressed: sending ? null : (composer == _Composer.forward ? _sendForward : _sendReply),
                        style: FilledButton.styleFrom(
                          minimumSize: const Size(0, 34),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                          shape: const StadiumBorder(),
                        ),
                        icon: const Icon(LucideIcons.send, size: 16),
                        label: Text(sending ? 'Sending…' : composer == _Composer.forward ? 'Forward' : 'Send reply'),
                      ),
                      if (composer == _Composer.reply && ai.replyDraft)
                        _ActionPill(
                          icon: LucideIcons.sparkles,
                          label: assisting ? 'Drafting…' : 'Suggest reply',
                          onPressed: assisting ? null : _suggest,
                        ),
                      if (composer == _Composer.reply)
                        _ActionPill(
                          icon: LucideIcons.paperclip,
                          label: 'Attach',
                          onPressed: _attach,
                        ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ThreadHeader extends StatelessWidget {
  const _ThreadHeader({
    required this.folder,
    required this.subject,
    required this.onBack,
    required this.onArchive,
    required this.onTrash,
    required this.onSpam,
    required this.onUnread,
  });

  final String folder;
  final String subject;
  final VoidCallback onBack;
  final VoidCallback onArchive;
  final VoidCallback onTrash;
  final VoidCallback onSpam;
  final VoidCallback onUnread;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Material(
      color: colors.bg,
      child: SafeArea(
        bottom: false,
        child: Column(
          children: [
            SizedBox(
              height: 52,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    const Text('Thread', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600, letterSpacing: -0.3)),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 160),
                        child: InkWell(
                          onTap: onBack,
                          borderRadius: BorderRadius.circular(8),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 6),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(LucideIcons.chevronLeft, size: 26, color: colors.accent),
                                Flexible(
                                  child: Text(
                                    folder,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(color: colors.accent, fontSize: 17, fontWeight: FontWeight.w400),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: PopupMenuButton<String>(
                        tooltip: 'Thread options',
                        onSelected: (value) {
                          if (value == 'spam') {
                            onSpam();
                          } else if (value == 'unread') {
                            onUnread();
                          }
                        },
                        color: colors.panel,
                        surfaceTintColor: Colors.transparent,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                          side: BorderSide(color: colors.border),
                        ),
                        itemBuilder: (context) => [
                          PopupMenuItem(
                            value: 'unread',
                            child: Row(
                              children: [
                                Icon(LucideIcons.mail, size: 16, color: colors.accent),
                                const SizedBox(width: 10),
                                Text('Mark as unread', style: TextStyle(color: colors.text, fontWeight: FontWeight.w500)),
                              ],
                            ),
                          ),
                          PopupMenuItem(
                            value: 'spam',
                            child: Row(
                              children: [
                                const Icon(LucideIcons.shieldAlert, size: 16, color: Color(0xFFF59E0B)),
                                const SizedBox(width: 10),
                                Text('Mark as spam', style: TextStyle(color: colors.text, fontWeight: FontWeight.w500)),
                              ],
                            ),
                          ),
                        ],
                        child: Container(
                          width: 36,
                          height: 36,
                          margin: const EdgeInsets.only(right: 6),
                          decoration: BoxDecoration(color: colors.accent, shape: BoxShape.circle),
                          child: Icon(LucideIcons.settings, size: 18, color: colors.onAccent),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Divider(height: 1, thickness: 0.6, color: colors.border),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 4, 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      subject,
                      style: TextStyle(color: colors.text, fontSize: 17, fontWeight: FontWeight.w600, height: 1.25, letterSpacing: -0.3),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Archive',
                    visualDensity: VisualDensity.compact,
                    onPressed: onArchive,
                    icon: Icon(LucideIcons.archive, color: colors.muted, size: 22),
                  ),
                  IconButton(
                    tooltip: 'Trash',
                    visualDensity: VisualDensity.compact,
                    onPressed: onTrash,
                    icon: const Icon(LucideIcons.trash2, color: Color(0xFFEF4444), size: 22),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MessageBody extends StatefulWidget {
  const _MessageBody({required this.body});

  final String body;

  @override
  State<_MessageBody> createState() => _MessageBodyState();
}

class _MessageBodyState extends State<_MessageBody> {
  var expanded = false;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final split = splitQuotedText(widget.body);
    final visible = split.visible.trim().isEmpty ? '(no body)' : split.visible;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(visible, style: TextStyle(color: colors.text, height: 1.4)),
        if (split.hasQuote) ...[
          TextButton(
            style: TextButton.styleFrom(
              visualDensity: VisualDensity.compact,
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              minimumSize: Size.zero,
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            onPressed: () => setState(() => expanded = !expanded),
            child: Text(expanded ? 'Hide quoted text' : 'Show quoted text'),
          ),
          if (expanded)
            Text(split.quoted, style: TextStyle(color: colors.text, height: 1.45, fontSize: 16)),
        ],
      ],
    );
  }
}

class _MailAttachment extends StatelessWidget {
  const _MailAttachment({required this.api, required this.file});

  final MailDeskApi api;
  final MailFile file;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final preview = file.canPreview
        ? () => openMailPreview(context, api: api, file: file)
        : null;

    final bar = _AttachmentBar(api: api, file: file, onOpen: preview);

    if (file.isImage && file.previewUrl != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          GestureDetector(onTap: preview, child: AuthedImage(api: api, url: file.previewUrl!)),
          bar,
        ],
      );
    }
    if (file.isVideo && file.previewUrl != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          RemoteVideo(api: api, url: file.previewUrl!),
          bar,
        ],
      );
    }
    if (file.isPdf && file.previewUrl != null) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: DecoratedBox(
          decoration: BoxDecoration(border: Border.all(color: colors.border), color: Colors.white),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              RemotePdf(api: api, url: file.previewUrl!, name: file.filename, height: 220),
              Material(color: colors.panel, child: bar),
            ],
          ),
        ),
      );
    }

    return bar;
  }
}

class _AttachmentBar extends StatelessWidget {
  const _AttachmentBar({required this.api, required this.file, this.onOpen});

  final MailDeskApi api;
  final MailFile file;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return InkWell(
      onTap: onOpen,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(10, 4, 2, 4),
        child: Row(
          children: [
            Icon(file.isPdf ? LucideIcons.fileText : LucideIcons.paperclip, size: 16, color: colors.accent),
            const SizedBox(width: 6),
            Expanded(
              child: Text(file.filename, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.text, fontSize: 13)),
            ),
            if (file.sizeLabel != null) Text(file.sizeLabel!, style: TextStyle(color: colors.muted, fontSize: 12)),
            DownloadButton(api: api, url: file.url, filename: file.filename, mimeType: file.contentType),
          ],
        ),
      ),
    );
  }
}

enum _Composer { reply, forward, closed }

class _ActionPill extends StatelessWidget {
  const _ActionPill({required this.icon, required this.label, this.selected = false, this.onPressed});

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final color = selected ? colors.accent : colors.secondary;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: OutlinedButton.icon(
        onPressed: onPressed,
        icon: Icon(icon, size: 15, color: color),
        label: Text(label),
        style: OutlinedButton.styleFrom(
          foregroundColor: color,
          side: BorderSide(color: selected ? colors.accent : colors.border),
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
