import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/models.dart';

void main() {
  test('presence labels an online peer and a quiet peer', () {
    final online = ConversationSummary(
      id: 1,
      type: 'direct',
      name: 'Ada',
      unreadCount: 0,
      participants: [
        const Participant(id: 1, name: 'Me', role: 'member', online: true),
        const Participant(id: 2, name: 'Ada', role: 'member', online: true, lastSeenAt: '2026-10-01T12:00:00Z'),
      ],
    );
    final quiet = ConversationSummary(
      id: 2,
      type: 'direct',
      name: 'Ada',
      unreadCount: 0,
      participants: [
        const Participant(id: 1, name: 'Me', role: 'member', online: true),
        Participant(
          id: 2,
          name: 'Ada',
          role: 'member',
          online: false,
          lastSeenAt: DateTime.now().toUtc().subtract(const Duration(minutes: 12)).toIso8601String(),
        ),
      ],
    );

    expect(conversationOnline(online, 1), isTrue);
    expect(presenceLabel(online, 1), 'Online');
    expect(conversationOnline(quiet, 1), isFalse);
    expect(presenceLabel(quiet, 1), 'Active 12m ago');
    final unknown = ConversationSummary(
      id: 4,
      type: 'direct',
      name: 'Ada',
      unreadCount: 0,
      participants: const [
        Participant(id: 1, name: 'Me', role: 'member', online: true),
        Participant(id: 2, name: 'Ada', role: 'member'),
      ],
    );
    expect(presenceLabel(unknown, 1), 'Offline');
  });

  test('group chats mention how many people are online', () {
    final group = ConversationSummary(
      id: 3,
      type: 'group',
      name: 'Team',
      unreadCount: 0,
      participants: const [
        Participant(id: 1, name: 'Me', role: 'member', online: true),
        Participant(id: 2, name: 'Ada', role: 'member', online: true),
        Participant(id: 3, name: 'Bo', role: 'member', online: false),
      ],
    );

    expect(presenceLabel(group, 1), '3 people · 1 online');
  });
}
