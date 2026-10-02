import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/chat_format.dart';
import 'package:maildesk/attachments.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/media.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/session.dart';
import 'package:maildesk/theme.dart';
class ConversationPage extends StatefulWidget {
  const ConversationPage({super.key, required this.conversation});

  final ConversationSummary conversation;

  @override
  State<ConversationPage> createState() => _ConversationPageState();
}

class _ConversationPageState extends State<ConversationPage> {
  final composer = TextEditingController();
  final scroll = ScrollController();
  var loading = true;
  var sending = false;
  var emojiOpen = false;
  ChatMessage? replyTo;
  String? error;
  final pending = <_Outgoing>[];
  var localId = 0;
  DateTime lastTyping = DateTime.fromMillisecondsSinceEpoch(0);
  Session? _session;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _session ??= Desk.read(context);
    final existing = _session!.messages[widget.conversation.id];
    if (existing != null && existing.isNotEmpty) {
      loading = false;
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) {
        return;
      }
      _session?.setOpenConversation(widget.conversation.id);
      _load();
    });
  }

  @override
  void dispose() {
    _session?.setOpenConversation(null);
    composer.dispose();
    scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final session = Desk.of(context);
    try {
      final detail = await session.api.conversation(widget.conversation.id);
      session.rememberMessages(widget.conversation.id, detail.messages);
      session.rememberConversation(detail.conversation);
      if (mounted) {
        setState(() {
          loading = false;
          error = null;
        });
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() {
          error = exception.message;
          loading = false;
        });
      }
    }
  }

  Future<void> _send() async {
    if (sending) {
      return;
    }
    if (pending.isNotEmpty) {
      await _sendPending();
      return;
    }
    final typed = composer.text.trim();
    if (typed.isEmpty) {
      return;
    }
    final quoted = replyTo;
    final body = quoted == null ? typed : '> ${quoted.body.trim()}\n$typed';
    final session = Desk.of(context);
    final placeholder = ChatMessage(
      id: --localId,
      conversationId: widget.conversation.id,
      body: body,
      userName: session.user?.name ?? '',
      userId: session.user?.id,
      createdAt: DateTime.now().toUtc().toIso8601String(),
      pending: true,
    );
    composer.clear();
    HapticFeedback.lightImpact();
    session.stageMessage(placeholder);
    if (mounted) {
      setState(() {
        error = null;
        emojiOpen = false;
        replyTo = null;
      });
    }
    try {
      await session.send(widget.conversation.id, body);
    } on ApiException catch (exception) {
      session.dropMessage(widget.conversation.id, placeholder.id);
      if (mounted) {
        if (composer.text.isEmpty) {
          composer.text = body;
        }
        setState(() => error = exception.message);
      }
    }
  }

  Future<void> _sendPending() async {
    final caption = composer.text.trim();
    final files = [...pending];
    setState(() {
      sending = true;
      error = null;
      emojiOpen = false;
      pending.clear();
    });
    composer.clear();
    HapticFeedback.lightImpact();
    var sent = 0;
    try {
      for (final file in files) {
        await Desk.of(context).sendFile(
          widget.conversation.id,
          path: file.path,
          body: sent == 0 ? caption : '',
          kind: file.kind,
        );
        sent++;
      }
    } on ApiException catch (exception) {
      if (mounted) {
        if (sent == 0) {
          composer.text = caption;
        }
        setState(() {
          error = exception.message;
          pending.insertAll(0, files.skip(sent));
        });
      }
    } finally {
      if (mounted) {
        setState(() => sending = false);
      }
    }
  }

  Future<void> _actions(ChatMessage message) async {
    HapticFeedback.mediumImpact();
    final colors = deskColors(context);
    final action = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: colors.panel,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            _ActionTile(icon: LucideIcons.copy, label: 'Copy', onTap: () => Navigator.pop(context, 'copy')),
            _ActionTile(icon: LucideIcons.reply, label: 'Reply', onTap: () => Navigator.pop(context, 'reply')),
            _ActionTile(icon: LucideIcons.forward, label: 'Forward', onTap: () => Navigator.pop(context, 'forward')),
            _ActionTile(icon: LucideIcons.trash2, label: 'Delete', danger: true, onTap: () => Navigator.pop(context, 'delete')),
          ],
        ),
      ),
    );
    if (!mounted || action == null) {
      return;
    }
    switch (action) {
      case 'copy':
        await Clipboard.setData(ClipboardData(text: message.body));
      case 'reply':
        setState(() {
          replyTo = message;
          emojiOpen = false;
        });
      case 'forward':
        await _forward(message);
      case 'delete':
        await _delete(message);
    }
  }

  Future<void> _delete(ChatMessage message) async {
    final colors = deskColors(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: colors.panel,
        title: Text('Delete message?', style: TextStyle(color: colors.text, fontSize: 18, fontWeight: FontWeight.w600)),
        content: Text('This removes it for everyone in the chat.', style: TextStyle(color: colors.secondary, height: 1.4)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Delete')),
        ],
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }
    final session = Desk.of(context);
    try {
      await session.api.deleteMessage(widget.conversation.id, message.id);
      session.dropMessage(widget.conversation.id, message.id);
      if (replyTo?.id == message.id && mounted) {
        setState(() => replyTo = null);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  Future<void> _forward(ChatMessage message) async {
    final session = Desk.of(context);
    final colors = deskColors(context);
    final targets = session.conversations.where((item) => item.id != widget.conversation.id).toList();
    final chosen = await showModalBottomSheet<int>(
      context: context,
      backgroundColor: colors.panel,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (context) => SafeArea(
        child: targets.isEmpty
            ? Padding(
                padding: const EdgeInsets.all(24),
                child: Text('No other chats to forward to.', style: TextStyle(color: colors.muted)),
              )
            : ListView(
                shrinkWrap: true,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                    child: Text('Forward to', style: TextStyle(color: colors.text, fontSize: 16, fontWeight: FontWeight.w600)),
                  ),
                  for (final chat in targets)
                    ListTile(
                      title: Text(chat.name, style: TextStyle(color: colors.text)),
                      onTap: () => Navigator.pop(context, chat.id),
                    ),
                ],
              ),
      ),
    );
    if (chosen == null || !mounted) {
      return;
    }
    try {
      await session.send(chosen, message.body);
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  void _wrap(String mark) {
    final value = composer.value;
    final text = value.text;
    var start = value.selection.start;
    var end = value.selection.end;
    if (start < 0 || end < 0) {
      start = text.length;
      end = text.length;
    }
    if (start > end) {
      final swap = start;
      start = end;
      end = swap;
    }
    final selected = text.substring(start, end);
    composer.value = TextEditingValue(
      text: text.replaceRange(start, end, '$mark$selected$mark'),
      selection: TextSelection.collapsed(offset: selected.isEmpty ? start + mark.length : start + mark.length * 2 + selected.length),
    );
  }

  void _stage(_Outgoing file) {
    setState(() {
      pending.add(file);
      error = null;
    });
  }

  Future<void> _pickImage() async {
    final image = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 80);
    if (image == null || !mounted) {
      return;
    }
    _stage(_Outgoing(path: image.path, name: image.name, kind: 'image'));
  }

  Future<void> _pickFile() async {
    final picked = await FilePicker.pickFiles();
    if (!mounted || picked.isEmpty) {
      return;
    }
    for (final file in picked) {
      if (file.path == null) {
        continue;
      }
      final local = LocalAttachment(path: file.path!, name: file.name);
      _stage(_Outgoing(path: local.path, name: local.name, kind: local.isImage ? 'image' : 'file'));
    }
  }

  void _emoji(String symbol) {
    final text = composer.text;
    final selection = composer.selection;
    final start = selection.start < 0 ? text.length : selection.start;
    final end = selection.end < 0 ? text.length : selection.end;
    composer.value = TextEditingValue(
      text: text.replaceRange(start, end, symbol),
      selection: TextSelection.collapsed(offset: start + symbol.length),
    );
    _typing();
  }

  void _typing() {
    final now = DateTime.now();
    if (now.difference(lastTyping).inSeconds < 2) {
      return;
    }
    lastTyping = now;
    Desk.of(context).pulseTyping(widget.conversation.id);
  }

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    final items = session.messages[widget.conversation.id] ?? [];
    final conversation = session.conversations.cast<ConversationSummary?>().firstWhere(
          (item) => item?.id == widget.conversation.id,
          orElse: () => widget.conversation,
        )!;
    final typing = session.typingConversationId == widget.conversation.id ? session.typingName : null;
    final presence = presenceLabel(conversation, session.user?.id);
    final online = conversationOnline(conversation, session.user?.id);
    final subtitle = typing != null
        ? '$typing is typing…'
        : presence;

    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(
        backgroundColor: colors.panel,
        surfaceTintColor: Colors.transparent,
        shape: Border(bottom: BorderSide(color: colors.border)),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Flexible(
                  child: Text(conversation.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
                if (online && !conversation.isGroup) ...[
                  const SizedBox(width: 8),
                  Container(
                    width: 8,
                    height: 8,
                    decoration: const BoxDecoration(color: Color(0xFF22C55E), shape: BoxShape.circle),
                  ),
                ],
              ],
            ),
            if (subtitle != null)
              Text(subtitle, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: online ? const Color(0xFF22C55E) : colors.muted)),
          ],
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: _ChatWallpaper(
              child: loading
                ? const Center(child: CircularProgressIndicator())
                : ListView.builder(
                    controller: scroll,
                    reverse: true,
                    padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
                    itemCount: items.length,
                    itemBuilder: (context, index) {
                      final message = items[items.length - 1 - index];
                      final mine = message.userId == session.user?.id;
                      final previous = index == items.length - 1 ? null : items[items.length - 2 - index];
                      final showName = conversation.isGroup && !mine && previous?.userId != message.userId;
                      return _Bubble(
                        message: message,
                        mine: mine,
                        showName: showName,
                        receipt: mine && !message.pending ? _receipt(message, conversation, session.user?.id) : null,
                        api: session.api,
                        onLongPress: mine && !message.pending && message.body.trim().isNotEmpty ? () => _actions(message) : null,
                      );
                    },
                  ),
            ),
          ),
          if (typing != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text('$typing is typing…', style: TextStyle(color: colors.muted, fontSize: 13)),
              ),
            ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ),
          if (emojiOpen)
            SizedBox(
              height: 180,
              child: GridView.count(
                crossAxisCount: 8,
                children: [
                  for (final symbol in _emojiSet)
                    InkWell(
                      onTap: () => _emoji(symbol),
                      child: Center(child: Text(symbol, style: const TextStyle(fontSize: 24))),
                    ),
                ],
              ),
            ),
          Container(
            padding: EdgeInsets.fromLTRB(12, 8, 12, 8 + MediaQuery.viewPaddingOf(context).bottom),
            decoration: BoxDecoration(
              color: colors.panel,
              border: Border(top: BorderSide(color: colors.border)),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (replyTo != null) ...[
                  _ReplyBar(
                    message: replyTo!,
                    onClear: () => setState(() => replyTo = null),
                  ),
                  const SizedBox(height: 8),
                ],
                Padding(
                  padding: const EdgeInsets.only(left: 4, bottom: 6),
                  child: Row(
                    children: [
                      _FormatMark(tooltip: 'Bold', icon: LucideIcons.bold, onPressed: () => _wrap('*')),
                      _FormatMark(tooltip: 'Italic', icon: LucideIcons.italic, onPressed: () => _wrap('_')),
                      _FormatMark(tooltip: 'Strikethrough', icon: LucideIcons.strikethrough, onPressed: () => _wrap('~')),
                      _FormatMark(tooltip: 'Monospace', icon: LucideIcons.code, onPressed: () => _wrap('`')),
                    ],
                  ),
                ),
                if (pending.isNotEmpty) ...[
                  _PendingPreview(
                    files: pending,
                    onRemove: (file) => setState(() => pending.remove(file)),
                  ),
                  const SizedBox(height: 8),
                ],
                Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Expanded(
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          color: colors.bubble,
                          borderRadius: BorderRadius.circular(22),
                          border: Border.all(color: colors.border),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            _ComposerButton(
                              tooltip: 'Emoji',
                              icon: emojiOpen ? LucideIcons.keyboard : LucideIcons.smile,
                              onPressed: () => setState(() => emojiOpen = !emojiOpen),
                            ),
                            Expanded(
                              child: TextField(
                                controller: composer,
                                minLines: 1,
                                maxLines: 5,
                                textAlignVertical: TextAlignVertical.center,
                                textCapitalization: TextCapitalization.sentences,
                                cursorColor: colors.accent,
                                style: TextStyle(color: colors.text, fontSize: 16, height: 1.25),
                                onChanged: (_) => _typing(),
                                decoration: InputDecoration(
                                  hintText: pending.isEmpty ? 'Message' : 'Add a caption',
                                  hintStyle: TextStyle(color: colors.muted, fontSize: 16, height: 1.25),
                                  isCollapsed: true,
                                  filled: false,
                                  border: InputBorder.none,
                                  enabledBorder: InputBorder.none,
                                  focusedBorder: InputBorder.none,
                                  disabledBorder: InputBorder.none,
                                  errorBorder: InputBorder.none,
                                  focusedErrorBorder: InputBorder.none,
                                  contentPadding: const EdgeInsets.symmetric(horizontal: 2, vertical: 12),
                                ),
                              ),
                            ),
                            _ComposerButton(
                              tooltip: 'Photo',
                              icon: LucideIcons.image,
                              onPressed: sending ? null : _pickImage,
                            ),
                            _ComposerButton(
                              tooltip: 'File',
                              icon: LucideIcons.paperclip,
                              onPressed: sending ? null : _pickFile,
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filled(
                      style: IconButton.styleFrom(
                        backgroundColor: colors.accent,
                        foregroundColor: colors.onAccent,
                        fixedSize: const Size(44, 44),
                        padding: EdgeInsets.zero,
                        alignment: Alignment.center,
                        iconSize: 18,
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                      onPressed: sending ? null : _send,
                      icon: const Padding(
                        padding: EdgeInsets.only(left: 1, bottom: 1),
                        child: Icon(LucideIcons.send),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  _Receipt _receipt(ChatMessage message, ConversationSummary conversation, int? me) {
    final sent = DateTime.tryParse(message.createdAt ?? '');
    if (sent == null) {
      return _Receipt.sent;
    }
    final others = conversation.participants.where((person) => person.id != me);
    if (others.isEmpty) {
      return _Receipt.sent;
    }
    var received = true;
    var read = true;
    for (final person in others) {
      final deliveredAt = DateTime.tryParse(person.lastDeliveredAt ?? '');
      final readAt = DateTime.tryParse(person.lastReadAt ?? '');
      final gotIt = (deliveredAt != null && !deliveredAt.isBefore(sent)) || (readAt != null && !readAt.isBefore(sent));
      final sawIt = readAt != null && !readAt.isBefore(sent);
      received = received && gotIt;
      read = read && sawIt;
    }
    if (read) {
      return _Receipt.read;
    }
    if (received) {
      return _Receipt.received;
    }
    return _Receipt.sent;
  }
}

enum _Receipt { sent, received, read }

class _ChatWallpaper extends StatelessWidget {
  const _ChatWallpaper({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return CustomPaint(
      painter: _WallpaperPainter(dark: dark),
      child: child,
    );
  }
}

class _WallpaperPainter extends CustomPainter {
  const _WallpaperPainter({required this.dark});

  final bool dark;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = dark ? const Color(0xFF0B141A) : const Color(0xFFE7DDD3),
    );
    final paint = Paint()
      ..color = (dark ? Colors.white : const Color(0xFF6B5344)).withValues(alpha: dark ? 0.055 : 0.12)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.15
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    const step = 96.0;
    for (var row = 0; row * step < size.height + step; row++) {
      for (var col = 0; col * step < size.width + step; col++) {
        final dx = col * step + (row.isEven ? 18 : 62);
        final dy = row * step + 22;
        _mark(canvas, paint, Offset(dx, dy), (col + row * 3) % 6);
      }
    }
  }

  void _mark(Canvas canvas, Paint paint, Offset origin, int kind) {
    final c = origin;
    switch (kind) {
      case 0:
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromCenter(center: c, width: 18, height: 13), const Radius.circular(4)), paint);
        canvas.drawLine(c + const Offset(-4, 6), c + const Offset(-7, 11), paint);
      case 1:
        canvas.drawCircle(c, 8, paint);
        canvas.drawCircle(c, 3, paint);
      case 2:
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromCenter(center: c.translate(0, 1), width: 16, height: 12), const Radius.circular(2)), paint);
        canvas.drawCircle(c.translate(0, 1), 3, paint);
      case 3:
        final heart = Path()
          ..moveTo(c.dx, c.dy + 6)
          ..cubicTo(c.dx - 10, c.dy - 2, c.dx - 4, c.dy - 9, c.dx, c.dy - 3)
          ..cubicTo(c.dx + 4, c.dy - 9, c.dx + 10, c.dy - 2, c.dx, c.dy + 6);
        canvas.drawPath(heart, paint);
      case 4:
        canvas.drawCircle(c, 8, paint);
        canvas.drawArc(Rect.fromCenter(center: c.translate(0, 1), width: 8, height: 6), 0.2, 2.6, false, paint);
      default:
        canvas.drawCircle(c, 8, paint);
        canvas.drawLine(c, c.translate(0, -4), paint);
        canvas.drawLine(c, c.translate(3, 2), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _WallpaperPainter oldDelegate) => oldDelegate.dark != dark;
}

class _Bubble extends StatelessWidget {
  const _Bubble({
    required this.message,
    required this.mine,
    required this.showName,
    required this.receipt,
    required this.api,
    this.onLongPress,
  });

  final ChatMessage message;
  final bool mine;
  final bool showName;
  final _Receipt? receipt;
  final MailDeskApi api;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final dark = Theme.of(context).brightness == Brightness.dark;
    final mineBubble = dark ? const Color(0xFF0E7490) : colors.accent;
    final ink = mine ? Colors.white : colors.text;
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: GestureDetector(
        onLongPress: onLongPress,
        child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        margin: const EdgeInsets.symmetric(vertical: 3),
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
        decoration: BoxDecoration(
          color: mine ? mineBubble : (dark ? const Color(0xFF1F2C34) : colors.panel),
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(mine ? 16 : 4),
            bottomRight: Radius.circular(mine ? 4 : 16),
          ),
          border: mine ? null : Border.all(color: colors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (showName)
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Text(message.userName, style: TextStyle(color: colors.accent, fontWeight: FontWeight.w500, fontSize: 12)),
              ),
            for (final file in message.attachments) ...[
              if (file.isImage)
                GestureDetector(
                  onTap: () {
                    HapticFeedback.selectionClick();
                    openImagePreview(context, api: api, url: '${file.url}?inline=1', filename: file.filename);
                  },
                  child: AuthedImage(api: api, url: '${file.url}?inline=1', height: 160),
                )
              else if (message.kind == 'voice' || (file.contentType ?? '').startsWith('audio/'))
                VoiceNote(api: api, url: '${file.url}?inline=1', mine: mine)
              else
                Text('${file.filename}${file.sizeLabel == null ? '' : ' · ${file.sizeLabel}'}', style: TextStyle(color: ink)),
              const SizedBox(height: 4),
            ],
            if (message.body.trim().isNotEmpty)
              ChatText(message.body, style: TextStyle(color: ink, fontSize: 16, height: 1.3)),
            const SizedBox(height: 2),
            Align(
              alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    shortTime(message.createdAt),
                    style: TextStyle(color: mine ? Colors.white : colors.muted, fontSize: 11),
                  ),
                  if (receipt != null) ...[
                    const SizedBox(width: 3),
                    Icon(
                      receipt == _Receipt.sent ? LucideIcons.check : LucideIcons.checkCheck,
                      size: 14,
                      color: Colors.white,
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
        ),
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({required this.icon, required this.label, required this.onTap, this.danger = false});

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final ink = danger ? const Color(0xFFEF4444) : colors.text;
    return ListTile(
      leading: Icon(icon, color: ink, size: 20),
      title: Text(label, style: TextStyle(color: ink, fontWeight: FontWeight.w500)),
      onTap: onTap,
    );
  }
}

class _ReplyBar extends StatelessWidget {
  const _ReplyBar({required this.message, required this.onClear});

  final ChatMessage message;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final quote = message.body.trim().replaceAll(RegExp(r'\s+'), ' ');
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
      decoration: BoxDecoration(
        color: colors.bg,
        borderRadius: BorderRadius.circular(12),
        border: Border(left: BorderSide(color: colors.accent, width: 3)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(message.userName, style: TextStyle(color: colors.accent, fontSize: 12, fontWeight: FontWeight.w600)),
                ChatText(
                  quote,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: colors.secondary, fontSize: 13),
                ),
              ],
            ),
          ),
          IconButton(onPressed: onClear, icon: Icon(LucideIcons.x, size: 16, color: colors.muted)),
        ],
      ),
    );
  }
}

class _FormatMark extends StatelessWidget {
  const _FormatMark({required this.tooltip, required this.icon, required this.onPressed});

  final String tooltip;
  final IconData icon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      visualDensity: VisualDensity.compact,
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints.tightFor(width: 36, height: 32),
      icon: Icon(icon, size: 16, color: colors.secondary),
    );
  }
}

class _ComposerButton extends StatelessWidget {
  const _ComposerButton({required this.tooltip, required this.icon, required this.onPressed});

  final String tooltip;
  final IconData icon;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      style: IconButton.styleFrom(
        fixedSize: const Size(40, 44),
        padding: EdgeInsets.zero,
        alignment: Alignment.center,
        iconSize: 20,
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        visualDensity: VisualDensity.standard,
      ),
      icon: Icon(icon, size: 20),
    );
  }
}

class _Outgoing {
  const _Outgoing({required this.path, required this.name, required this.kind});

  final String path;
  final String name;
  final String kind;

  bool get isImage => kind == 'image';
}

class _PendingPreview extends StatelessWidget {
  const _PendingPreview({required this.files, required this.onRemove});

  final List<_Outgoing> files;
  final ValueChanged<_Outgoing> onRemove;

  @override
  Widget build(BuildContext context) {
    if (files.length == 1 && files.first.isImage) {
      return _ImagePreview(file: files.first, onRemove: () => onRemove(files.first));
    }
    return SizedBox(
      height: 88,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: files.length,
        separatorBuilder: (context, index) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          final file = files[index];
          if (file.isImage) {
            return _Thumb(
              file: file,
              onRemove: () => onRemove(file),
              onOpen: () => _openLocalImage(context, file.path),
            );
          }
          return _FileChip(file: file, onRemove: () => onRemove(file));
        },
      ),
    );
  }
}

void _openLocalImage(BuildContext context, String path) {
  showDialog<void>(
    context: context,
    builder: (context) => Dialog(
      backgroundColor: Colors.black,
      insetPadding: const EdgeInsets.all(16),
      child: InteractiveViewer(
        minScale: 1,
        maxScale: 4,
        child: Image.file(File(path), fit: BoxFit.contain),
      ),
    ),
  );
}

class _ImagePreview extends StatelessWidget {
  const _ImagePreview({required this.file, required this.onRemove});

  final _Outgoing file;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Stack(
      children: [
        GestureDetector(
          onTap: () => _openLocalImage(context, file.path),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(16),
            child: DecoratedBox(
              decoration: BoxDecoration(border: Border.all(color: colors.border)),
              child: Image.file(
                File(file.path),
                height: 180,
                width: double.infinity,
                fit: BoxFit.cover,
                errorBuilder: (context, error, stack) => _BrokenPreview(colors: colors, wide: true),
              ),
            ),
          ),
        ),
        Positioned(top: 8, right: 8, child: _PreviewClose(onPressed: onRemove)),
      ],
    );
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.file, required this.onRemove, required this.onOpen});

  final _Outgoing file;
  final VoidCallback onRemove;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Stack(
      children: [
        GestureDetector(
          onTap: onOpen,
          child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Image.file(
            File(file.path),
            width: 88,
            height: 88,
            fit: BoxFit.cover,
            errorBuilder: (context, error, stack) => _BrokenPreview(colors: colors),
          ),
          ),
        ),
        Positioned(top: 4, right: 4, child: _PreviewClose(onPressed: onRemove)),
      ],
    );
  }
}

class _FileChip extends StatelessWidget {
  const _FileChip({required this.file, required this.onRemove});

  final _Outgoing file;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Stack(
      children: [
        Container(
          width: 180,
          height: 88,
          padding: const EdgeInsets.fromLTRB(12, 28, 28, 10),
          decoration: BoxDecoration(
            color: colors.bg,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: colors.border),
          ),
          child: Row(
            children: [
              Icon(LucideIcons.paperclip, size: 16, color: colors.accent),
              const SizedBox(width: 8),
              Expanded(
                child: Text(file.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.text, fontSize: 13)),
              ),
            ],
          ),
        ),
        Positioned(top: 4, right: 4, child: _PreviewClose(onPressed: onRemove)),
      ],
    );
  }
}

class _BrokenPreview extends StatelessWidget {
  const _BrokenPreview({required this.colors, this.wide = false});

  final MailDeskColors colors;
  final bool wide;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: wide ? double.infinity : 88,
      height: wide ? 180 : 88,
      color: colors.bg,
      alignment: Alignment.center,
      child: Icon(LucideIcons.imageOff, color: colors.muted, size: 22),
    );
  }
}

class _PreviewClose extends StatelessWidget {
  const _PreviewClose({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: const Color(0xCC09090B),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onPressed,
        child: const Padding(
          padding: EdgeInsets.all(4),
          child: Icon(LucideIcons.x, size: 14, color: Colors.white),
        ),
      ),
    );
  }
}

const _emojiSet = [
  '😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎',
  '🤔', '😅', '😢', '😭', '😡', '🙏', '👍', '👎',
  '👏', '🔥', '🎉', '❤️', '✅', '⭐', '📌', '📎',
  '📷', '🎤', '💬', '📧', '💼', '🚀', '👀', '🤝',
];
