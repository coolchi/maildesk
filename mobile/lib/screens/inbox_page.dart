import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/empty.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/screens/compose_page.dart';
import 'package:maildesk/screens/mail_page.dart';
import 'package:maildesk/theme.dart';

class InboxPage extends StatefulWidget {
  const InboxPage({
    super.key,
    required this.folder,
    this.reload = 0,
    this.onSent,
  });

  final String folder;
  final int reload;
  final VoidCallback? onSent;

  @override
  State<InboxPage> createState() => _InboxPageState();
}

class _InboxPageState extends State<InboxPage> {
  final search = TextEditingController();
  var loading = false;
  List<MailThread> threads = [];
  List<MailDraft> drafts = [];
  final selected = <int>{};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(InboxPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.folder != widget.folder ||
        oldWidget.reload != widget.reload) {
      selected.clear();
      _load();
    }
  }

  Future<void> _load() async {
    final session = Desk.read(context);
    final folder = widget.folder;
    setState(() => loading = true);
    try {
      if (folder == 'drafts') {
        drafts = await session.api.drafts();
      } else {
        threads = await session.api.inbox(folder: folder, query: search.text);
        selected.removeWhere(
          (id) => threads.every((thread) => thread.id != id),
        );
        if (folder == 'inbox') {
          session.setThreads(threads);
        }
      }
      if (mounted) {
        setState(() => loading = false);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => loading = false);
        if (exception.status != 401 && exception.status != 402) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(exception.message)));
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final folder = widget.folder;
    final title = switch (folder) {
      'sent' => 'Sent',
      'drafts' => 'Drafts',
      'archive' => 'Archive',
      'spam' => 'Spam',
      'trash' => 'Trash',
      _ => 'Inbox',
    };
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: TextField(
            controller: search,
            textInputAction: TextInputAction.search,
            onSubmitted: (_) => _load(),
            decoration: InputDecoration(
              hintText: 'Search ${title.toLowerCase()}',
              prefixIcon: const Icon(LucideIcons.search, size: 18),
              prefixIconConstraints: const BoxConstraints(
                minWidth: 40,
                minHeight: 36,
              ),
              isDense: true,
            ),
          ),
        ),
        if (selected.isNotEmpty) _selectionBar(colors),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: folder == 'drafts'
                ? _drafts(colors)
                : _threads(colors, title),
          ),
        ),
      ],
    );
  }

  Widget _blank({required Widget child}) {
    return LayoutBuilder(
      builder: (context, constraints) {
        return ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [SizedBox(height: constraints.maxHeight, child: child)],
        );
      },
    );
  }

  Color _rule() {
    return Theme.of(context).brightness == Brightness.dark
        ? const Color(0xFF52525B)
        : const Color(0xFFA1A1AA);
  }

  Widget _selectionBar(MailDeskColors colors) {
    return Material(
      color: colors.panel,
      child: Container(
        decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: _rule())),
        ),
        child: Row(
          children: [
            IconButton(
              tooltip: 'Cancel',
              onPressed: () => setState(() => selected.clear()),
              icon: Icon(LucideIcons.x, size: 18, color: colors.text),
            ),
            Text(
              '${selected.length} selected',
              style: TextStyle(
                color: colors.text,
                fontSize: 15,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            IconButton(
              tooltip: 'Mark as unread',
              onPressed: () => _applySelected('unread'),
              icon: Icon(LucideIcons.mail, size: 18, color: colors.text),
            ),
            IconButton(
              tooltip: widget.folder == 'archive' ? 'Move to inbox' : 'Archive',
              onPressed: () => _applySelected('archive'),
              icon: Icon(
                widget.folder == 'archive'
                    ? LucideIcons.inbox
                    : LucideIcons.archive,
                size: 18,
                color: colors.text,
              ),
            ),
            IconButton(
              tooltip: widget.folder == 'trash' ? 'Restore' : 'Delete',
              onPressed: () => _applySelected('trash'),
              icon: Icon(
                widget.folder == 'trash'
                    ? LucideIcons.inbox
                    : LucideIcons.trash2,
                size: 18,
                color: const Color(0xFFEF4444),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _applySelected(String action) async {
    final ids = selected.toList();
    if (ids.isEmpty) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    setState(() => selected.clear());
    try {
      final api = Desk.read(context).api;
      for (final id in ids) {
        await api.mailAction(id, action);
      }
      final label = switch (action) {
        'archive' => widget.folder == 'archive' ? 'Moved to inbox' : 'Archived',
        'unread' => 'Marked as unread',
        _ => widget.folder == 'trash' ? 'Restored' : 'Moved to trash',
      };
      messenger.showSnackBar(SnackBar(content: Text(label)));
      await _load();
    } on ApiException catch (exception) {
      messenger.showSnackBar(SnackBar(content: Text(exception.message)));
      await _load();
    }
  }

  Future<void> _archiveDismissed(int id) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() {
      threads = [
        for (final thread in threads)
          if (thread.id != id) thread,
      ];
      selected.remove(id);
    });
    try {
      await Desk.read(context).api.mailAction(id, 'archive');
    } on ApiException catch (exception) {
      messenger.showSnackBar(SnackBar(content: Text(exception.message)));
      await _load();
    }
  }

  Future<void> _move(int id, String action) async {
    if (action == 'spam') {
      final colors = deskColors(context);
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          backgroundColor: colors.panel,
          title: Text(
            'Mark as spam?',
            style: TextStyle(
              color: colors.text,
              fontSize: 18,
              fontWeight: FontWeight.w600,
            ),
          ),
          content: Text(
            'This conversation will move to Spam.',
            style: TextStyle(color: colors.secondary, height: 1.4),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Mark as spam'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted) {
        return;
      }
    }
    final messenger = ScaffoldMessenger.of(context);
    try {
      await Desk.read(context).api.mailAction(id, action);
      await _load();
    } on ApiException catch (exception) {
      messenger.showSnackBar(SnackBar(content: Text(exception.message)));
    }
  }

  PopupMenuItem<String> _moveItem({
    required String value,
    required IconData icon,
    required String label,
    required Color tone,
    required Color text,
  }) {
    return PopupMenuItem(
      value: value,
      height: 46,
      padding: const EdgeInsets.symmetric(horizontal: 10),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: tone.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(icon, size: 15, color: tone),
          ),
          const SizedBox(width: 10),
          Text(
            label,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w500,
              color: text,
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _compose() async {
    final sent = await Navigator.of(
      context,
    ).push<bool>(MaterialPageRoute(builder: (_) => const ComposePage()));
    if (sent == true) {
      widget.onSent?.call();
    }
  }

  Widget _threads(MailDeskColors colors, String title) {
    if (threads.isEmpty) {
      final folder = widget.folder;
      return _blank(
        child: loading
            ? const Center(child: CircularProgressIndicator())
            : EmptyPane(
                icon: switch (folder) {
                  'sent' => LucideIcons.send,
                  'archive' => LucideIcons.archive,
                  'spam' => LucideIcons.shieldAlert,
                  'trash' => LucideIcons.trash2,
                  _ => LucideIcons.inbox,
                },
                title: '$title is empty',
                message: switch (folder) {
                  'sent' => 'Mail you send will show up here.',
                  'archive' => 'Conversations you archive will show up here.',
                  'spam' => 'Nothing has been marked as spam.',
                  'trash' => 'Deleted conversations will show up here.',
                  _ => 'New mail will show up here.',
                },
                color: switch (folder) {
                  'archive' => colors.secondary,
                  'spam' => const Color(0xFFF59E0B),
                  'trash' => const Color(0xFFEF4444),
                  _ => null,
                },
                actionLabel: folder == 'inbox' ? 'Compose' : null,
                onAction: folder == 'inbox' ? _compose : null,
              ),
      );
    }
    final selecting = selected.isNotEmpty;
    final canSwipe =
        !selecting && widget.folder != 'archive' && widget.folder != 'trash';
    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      itemCount: threads.length,
      separatorBuilder: (context, index) =>
          Divider(height: 1, thickness: 1, color: _rule()),
      itemBuilder: (context, index) {
        final thread = threads[index];
        final chosen = selected.contains(thread.id);
        return Dismissible(
          key: ValueKey('mail-${thread.id}'),
          direction: canSwipe
              ? DismissDirection.endToStart
              : DismissDirection.none,
          background: const SizedBox.shrink(),
          secondaryBackground: ColoredBox(
            color: colors.accent,
            child: Align(
              alignment: Alignment.centerRight,
              child: Padding(
                padding: const EdgeInsets.only(right: 24),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(LucideIcons.archive, color: colors.onAccent, size: 20),
                    const SizedBox(width: 8),
                    Text(
                      'Archive',
                      style: TextStyle(
                        color: colors.onAccent,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          onDismissed: (_) => _archiveDismissed(thread.id),
          child: Material(
            color: chosen
                ? colors.accent.withValues(alpha: 0.14)
                : Colors.transparent,
            child: InkWell(
              onLongPress: () {
                HapticFeedback.mediumImpact();
                setState(() => selected.add(thread.id));
              },
              onTap: () async {
                if (selecting) {
                  HapticFeedback.selectionClick();
                  setState(() {
                    if (!selected.add(thread.id)) {
                      selected.remove(thread.id);
                    }
                  });
                  return;
                }
                HapticFeedback.selectionClick();
                await Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => MailPage(thread: thread)),
                );
                if (mounted) {
                  await _load();
                }
              },
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Stack(
                      clipBehavior: Clip.none,
                      children: [
                        CircleAvatar(
                          radius: 24,
                          backgroundColor: thread.unread
                              ? colors.accent.withValues(alpha: 0.16)
                              : colors.border,
                          foregroundColor: thread.unread
                              ? colors.accent
                              : colors.secondary,
                          child: Text(
                            initials(thread.sender),
                            style: const TextStyle(
                              fontWeight: FontWeight.w700,
                              fontSize: 18,
                            ),
                          ),
                        ),
                        if (selecting)
                          Positioned.fill(
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: chosen
                                    ? colors.accent
                                    : colors.bg.withValues(alpha: 0.55),
                                border: Border.all(
                                  color: chosen ? colors.accent : colors.muted,
                                  width: 2,
                                ),
                              ),
                              child: chosen
                                  ? Icon(
                                      LucideIcons.check,
                                      color: colors.onAccent,
                                      size: 18,
                                    )
                                  : null,
                            ),
                          )
                        else if (thread.unread)
                          Positioned(
                            right: -1,
                            top: -1,
                            child: Container(
                              width: 11,
                              height: 11,
                              decoration: BoxDecoration(
                                color: colors.accent,
                                shape: BoxShape.circle,
                                border: Border.all(color: colors.bg, width: 2),
                              ),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  thread.sender,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    fontSize: 16,
                                    fontWeight: thread.unread
                                        ? FontWeight.w700
                                        : FontWeight.w500,
                                    color: thread.unread
                                        ? colors.text
                                        : colors.muted,
                                  ),
                                ),
                              ),
                              Text(
                                thread.updated,
                                style: TextStyle(
                                  fontSize: 12.5,
                                  fontWeight: FontWeight.w500,
                                  color: thread.unread
                                      ? colors.accent
                                      : colors.muted,
                                ),
                              ),
                            ],
                          ),
                          Text(
                            thread.subject,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: 15,
                              color: thread.unread
                                  ? colors.text
                                  : colors.secondary,
                              fontWeight: thread.unread
                                  ? FontWeight.w600
                                  : FontWeight.w500,
                            ),
                          ),
                          if (thread.snippet.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Text(
                                thread.snippet,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: colors.muted,
                                  fontSize: 14,
                                  height: 1.35,
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                    PopupMenuButton<String>(
                      tooltip: 'Move',
                      padding: EdgeInsets.zero,
                      color: colors.panel,
                      elevation: 8,
                      shadowColor: const Color(0x66000000),
                      surfaceTintColor: Colors.transparent,
                      menuPadding: const EdgeInsets.symmetric(vertical: 6),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                        side: BorderSide(color: colors.border),
                      ),
                      icon: Icon(
                        LucideIcons.moreHorizontal,
                        color: colors.muted,
                        size: 18,
                      ),
                      onSelected: (action) => _move(thread.id, action),
                      itemBuilder: (context) => [
                        if (thread.unread)
                          _moveItem(
                            value: 'read',
                            icon: LucideIcons.mailOpen,
                            label: 'Mark as read',
                            tone: colors.accent,
                            text: colors.text,
                          )
                        else
                          _moveItem(
                            value: 'unread',
                            icon: LucideIcons.mail,
                            label: 'Mark as unread',
                            tone: colors.accent,
                            text: colors.text,
                          ),
                        const PopupMenuDivider(height: 8),
                        if (widget.folder != 'archive')
                          _moveItem(
                            value: 'archive',
                            icon: LucideIcons.archive,
                            label: 'Archive',
                            tone: colors.secondary,
                            text: colors.text,
                          ),
                        if (widget.folder != 'spam')
                          _moveItem(
                            value: 'spam',
                            icon: LucideIcons.shieldAlert,
                            label: 'Spam',
                            tone: const Color(0xFFF59E0B),
                            text: colors.text,
                          ),
                        if (widget.folder != 'trash')
                          _moveItem(
                            value: 'trash',
                            icon: LucideIcons.trash2,
                            label: 'Trash',
                            tone: const Color(0xFFEF4444),
                            text: colors.text,
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _drafts(MailDeskColors colors) {
    if (drafts.isEmpty) {
      return _blank(
        child: loading
            ? const Center(child: CircularProgressIndicator())
            : const EmptyPane(
                icon: LucideIcons.fileEdit,
                title: 'No drafts',
                message: 'Messages you save will show up here.',
              ),
      );
    }
    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      itemCount: drafts.length,
      separatorBuilder: (context, index) =>
          Divider(height: 1, thickness: 1, color: _rule()),
      itemBuilder: (context, index) {
        final draft = drafts[index];
        return ListTile(
          title: Text(
            draft.subject.isEmpty ? '(no subject)' : draft.subject,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          subtitle: Text(
            draft.to ?? 'No recipient',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          trailing: Text(
            draft.updated ?? '',
            style: TextStyle(fontSize: 12, color: colors.muted),
          ),
          onTap: () async {
            HapticFeedback.selectionClick();
            final sent = await Navigator.of(context).push<bool>(
              MaterialPageRoute(builder: (_) => ComposePage(draft: draft)),
            );
            if (!mounted) {
              return;
            }
            if (sent == true) {
              widget.onSent?.call();
            } else {
              await _load();
            }
          },
        );
      },
    );
  }
}

class InboxFooter extends StatelessWidget {
  const InboxFooter({super.key, required this.folder, required this.onSelect});

  final String folder;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    const size = 64.0;
    return Stack(
      clipBehavior: Clip.none,
      alignment: Alignment.topCenter,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: size / 2),
          child: Container(
            decoration: BoxDecoration(
              color: colors.panel,
              border: Border(top: BorderSide(color: colors.border)),
            ),
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                _item(context, 'inbox', LucideIcons.inbox, 'Inbox', colors),
                _item(context, 'sent', LucideIcons.send, 'Sent', colors),
                const Expanded(child: SizedBox(height: 40)),
                _item(context, 'drafts', LucideIcons.fileText, 'Drafts', colors),
                _item(
                  context,
                  'more',
                  LucideIcons.folders,
                  'More',
                  colors,
                ),
              ],
            ),
          ),
        ),
        Material(
          color: colors.accent,
          elevation: 6,
          shadowColor: colors.accent.withValues(alpha: 0.45),
          shape: const CircleBorder(),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: () => onSelect('compose'),
            child: SizedBox(
              width: size,
              height: size,
              child: Icon(LucideIcons.pencil, color: colors.onAccent, size: 28),
            ),
          ),
        ),
      ],
    );
  }

  Widget _item(
    BuildContext context,
    String value,
    IconData icon,
    String label,
    MailDeskColors colors,
  ) {
    final selected = folder == value;
    return Expanded(
      child: InkWell(
        onTap: () => onSelect(value),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              color: selected ? colors.accent : colors.muted,
              size: 20,
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                color: selected ? colors.accent : colors.muted,
                fontWeight: FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
