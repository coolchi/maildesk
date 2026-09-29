import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/screens/conversation_page.dart';
import 'package:maildesk/screens/mail_page.dart';
import 'package:maildesk/session.dart';
import 'package:maildesk/theme.dart';

int noticeCount(Session session) {
  return session.threads.where((thread) => thread.unread).length +
      session.conversations.where((conversation) => conversation.unreadCount > 0).length;
}

class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key});

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  var clearing = false;

  Future<void> _clear() async {
    final session = Desk.of(context);
    setState(() => clearing = true);
    try {
      await session.api.markInboxRead();
      for (final conversation in session.conversations.where((item) => item.unreadCount > 0)) {
        await session.api.markChatRead(conversation.id);
      }
      await session.refresh();
    } on ApiException catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(exception.message)));
      }
    } finally {
      if (mounted) {
        setState(() => clearing = false);
      }
    }
  }

  Future<void> _openMail(MailThread thread) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => MailPage(thread: thread)));
    if (mounted) {
      await Desk.read(context).refresh();
    }
  }

  Future<void> _openChat(ConversationSummary conversation) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConversationPage(conversation: conversation)));
    if (mounted) {
      await Desk.read(context).refresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    final mail = session.threads.where((thread) => thread.unread).toList();
    final chats = session.conversations.where((conversation) => conversation.unreadCount > 0).toList();
    final count = mail.length + chats.length;

    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (count > 0)
            TextButton(
              onPressed: clearing ? null : _clear,
              child: clearing
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Mark all read'),
            ),
        ],
      ),
      body: count == 0
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 64,
                      height: 64,
                      decoration: BoxDecoration(
                        color: colors.accent.withValues(alpha: 0.12),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(LucideIcons.bell, color: colors.accent, size: 28),
                    ),
                    const SizedBox(height: 16),
                    Text('You\'re all caught up', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w500, color: colors.text)),
                    const SizedBox(height: 6),
                    Text('New mail and chats will show up here.', style: TextStyle(color: colors.muted), textAlign: TextAlign.center),
                  ],
                ),
              ),
            )
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              children: [
                Text(count == 1 ? '1 unread' : '$count unread', style: TextStyle(color: colors.muted, fontSize: 13)),
                const SizedBox(height: 12),
                if (mail.isNotEmpty) ...[
                  _Heading(label: 'Mail'),
                  for (final thread in mail)
                    _Notice(
                      icon: LucideIcons.inbox,
                      title: thread.sender,
                      body: thread.subject,
                      time: thread.updated,
                      onTap: () => _openMail(thread),
                    ),
                ],
                if (chats.isNotEmpty) ...[
                  _Heading(label: 'Chats'),
                  for (final conversation in chats)
                    _Notice(
                      icon: conversation.isGroup ? LucideIcons.users : LucideIcons.messageCircle,
                      title: conversation.name,
                      body: (conversation.preview ?? '').isEmpty ? 'New message' : conversation.preview!,
                      time: conversation.unreadCount == 1 ? '1 new' : '${conversation.unreadCount} new',
                      onTap: () => _openChat(conversation),
                    ),
                ],
              ],
            ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8, top: 4),
      child: Text(label, style: TextStyle(color: colors.muted, fontSize: 12, fontWeight: FontWeight.w500)),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({
    required this.icon,
    required this.title,
    required this.body,
    required this.time,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String body;
  final String time;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: colors.panel,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(color: colors.border),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: colors.accent.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(icon, size: 18, color: colors.accent),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 15)),
                      const SizedBox(height: 2),
                      Text(body, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.secondary, fontSize: 13)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration: BoxDecoration(color: colors.accent, shape: BoxShape.circle),
                    ),
                    const SizedBox(height: 8),
                    Text(time, style: TextStyle(color: colors.muted, fontSize: 11)),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
