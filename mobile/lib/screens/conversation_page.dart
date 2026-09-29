import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:maildesk/api.dart';
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
  String? error;
  final pending = <_Outgoing>[];
  DateTime lastTyping = DateTime.fromMillisecondsSinceEpoch(0);
  Session? _session;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _session ??= Desk.read(context);
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
      session.messages[widget.conversation.id] = detail.messages;
      if (mounted) {
        setState(() => loading = false);
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
    final body = composer.text.trim();
    if (body.isEmpty) {
      return;
    }
    setState(() => sending = true);
    composer.clear();
    HapticFeedback.lightImpact();
    try {
      await Desk.of(context).send(widget.conversation.id, body);
    } on ApiException catch (exception) {
      if (mounted) {
        composer.text = body;
        setState(() => error = exception.message);
      }
    } finally {
      if (mounted) {
        setState(() => sending = false);
      }
    }
  }

  Future<void> _sendPending() async {
    final caption = composer.text.trim();
    final files = [...pending];
    setState(() {
      sending = true;
      error = null;
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
    final subtitle = conversation.isGroup ? '${conversation.participants.length} people' : null;

    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(conversation.name, maxLines: 1, overflow: TextOverflow.ellipsis),
            if (subtitle != null)
              Text(subtitle, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: colors.muted)),
          ],
        ),
      ),
      body: Column(
        children: [
          Expanded(
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
                        receipt: mine ? _receipt(message, conversation, session.user?.id) : null,
                        api: session.api,
                      );
                    },
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

class _Bubble extends StatelessWidget {
  const _Bubble({
    required this.message,
    required this.mine,
    required this.showName,
    required this.receipt,
    required this.api,
  });

  final ChatMessage message;
  final bool mine;
  final bool showName;
  final _Receipt? receipt;
  final MailDeskApi api;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        margin: const EdgeInsets.symmetric(vertical: 3),
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
        decoration: BoxDecoration(
          color: mine ? colors.accent : colors.panel,
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
                Text('${file.filename}${file.sizeLabel == null ? '' : ' · ${file.sizeLabel}'}', style: TextStyle(color: mine ? colors.onAccent : colors.text)),
              if (message.kind != 'voice' && !(file.contentType ?? '').startsWith('audio/'))
                Align(
                  alignment: Alignment.centerRight,
                  child: DownloadButton(
                    api: api,
                    url: file.url,
                    filename: file.filename,
                    mimeType: file.contentType,
                    color: mine ? colors.onAccent : colors.accent,
                  ),
                ),
              const SizedBox(height: 4),
            ],
            if (message.body.trim().isNotEmpty)
              Text(message.body, style: TextStyle(color: mine ? colors.onAccent : colors.text, fontSize: 16, height: 1.3)),
            const SizedBox(height: 2),
            Align(
              alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    shortTime(message.createdAt),
                    style: TextStyle(color: mine ? colors.onAccent.withValues(alpha: 0.75) : colors.muted, fontSize: 11),
                  ),
                  if (receipt != null) ...[
                    const SizedBox(width: 3),
                    Icon(
                      receipt == _Receipt.sent ? LucideIcons.check : LucideIcons.checkCheck,
                      size: 14,
                      color: receipt == _Receipt.read
                          ? (Theme.of(context).brightness == Brightness.dark ? Colors.white : const Color(0xFF083344))
                          : colors.onAccent.withValues(alpha: 0.8),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
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
