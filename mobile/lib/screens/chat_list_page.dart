import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/empty.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/screens/conversation_page.dart';
import 'package:maildesk/screens/people_page.dart';
import 'package:maildesk/theme.dart';

class ChatListPage extends StatelessWidget {
  const ChatListPage({super.key});

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    if (session.conversations.isEmpty) {
      return EmptyPane(
        icon: LucideIcons.messageCircle,
        title: 'No chats yet',
        message: 'Start a direct message or a group.',
        actionLabel: 'New chat',
        onAction: () => ChatListPage.openNew(context),
      );
    }

    return RefreshIndicator(
      onRefresh: session.refresh,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        itemCount: session.conversations.length,
        separatorBuilder: (context, index) => Divider(height: 1, color: colors.border),
        itemBuilder: (context, index) {
          final conversation = session.conversations[index];
          return _Row(conversation: conversation, userId: session.user?.id);
        },
      ),
    );
  }

  static Future<void> openNew(BuildContext context) async {
    final opened = await Navigator.of(context).push<ConversationSummary>(
      MaterialPageRoute(builder: (_) => const PeoplePage()),
    );
    if (opened != null && context.mounted) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConversationPage(conversation: opened)));
    }
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.conversation, required this.userId});

  final ConversationSummary conversation;
  final int? userId;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final unread = conversation.unreadCount > 0;
    final online = conversationOnline(conversation, userId);
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      leading: _Avatar(
        colors: colors,
        unread: unread,
        online: online,
        group: conversation.isGroup,
        label: conversation.name,
      ),
      title: Row(
        children: [
          if (conversation.pinned) ...[
            Icon(LucideIcons.pin, size: 14, color: colors.accent),
            const SizedBox(width: 4),
          ],
          Expanded(
            child: Text(
              conversation.name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 14, fontWeight: unread ? FontWeight.w500 : FontWeight.w400, color: colors.text),
            ),
          ),
        ],
      ),
      subtitle: Text(
        conversation.preview ?? 'No messages yet',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(color: unread ? colors.secondary : colors.muted, fontWeight: FontWeight.w400, fontSize: 13),
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(shortTime(conversation.lastMessageAt), style: TextStyle(fontSize: 12, color: unread ? colors.accent : colors.muted)),
          const SizedBox(height: 6),
          if (unread)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
              decoration: BoxDecoration(color: colors.accent, borderRadius: BorderRadius.circular(99)),
              child: Text(
                '${conversation.unreadCount}',
                style: TextStyle(color: colors.onAccent, fontSize: 11, fontWeight: FontWeight.w500),
              ),
            )
          else
            const SizedBox(height: 16),
        ],
      ),
      onTap: () {
        HapticFeedback.selectionClick();
        Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConversationPage(conversation: conversation)));
      },
      onLongPress: () async {
        HapticFeedback.mediumImpact();
        try {
          await Desk.of(context).pin(conversation);
        } on ApiException catch (exception) {
          if (context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(exception.message)));
          }
        }
      },
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({
    required this.colors,
    required this.unread,
    required this.online,
    required this.group,
    required this.label,
  });

  final MailDeskColors colors;
  final bool unread;
  final bool online;
  final bool group;
  final String label;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 44,
      height: 44,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          CircleAvatar(
            radius: 22,
            backgroundColor: colors.accent.withValues(alpha: unread ? 0.18 : 0.1),
            foregroundColor: colors.accent,
            child: group ? const Icon(LucideIcons.users, size: 20) : Text(initials(label)),
          ),
          if (online)
            Positioned(
              right: 0,
              bottom: 0,
              child: Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(
                  color: const Color(0xFF22C55E),
                  shape: BoxShape.circle,
                  border: Border.all(color: colors.bg, width: 2),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
