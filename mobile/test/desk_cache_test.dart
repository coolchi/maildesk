import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/desk_cache.dart';
import 'package:maildesk/models.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late Directory root;
  late DeskCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    root = await Directory.systemTemp.createTemp('desk_cache_test');
    cache = DeskCache(await SharedPreferences.getInstance(), root);
  });

  tearDown(() {
    if (root.existsSync()) {
      root.deleteSync(recursive: true);
    }
  });

  test('lists and a letter round-trip and stay on disk', () async {
    const thread = MailThread(id: 4, subject: 'Hello', snippet: 'Hi', unread: true, updated: 'today', fromName: 'Ada');
    const chat = ConversationSummary(id: 2, type: 'direct', name: 'Ada', unreadCount: 1, participants: []);
    await cache.saveLists(organizationId: 7, threads: [thread], conversations: [chat]);

    final lists = cache.readLists(7);
    expect(lists?.threads.single.subject, 'Hello');
    expect(lists?.conversations.single.name, 'Ada');
    expect(sameMailThreads([thread], lists!.threads), isTrue);
    expect(sameConversations([chat], lists.conversations), isTrue);

    const letter = MailDetail(subject: 'Hello', messages: [
      MailMessage(from: 'ada@test.com', body: 'Hi', sent: 'now', html: '<p>Hi</p>'),
    ]);
    await cache.saveMail(7, 4, letter);
    expect(cache.readMail(7, 4)?.messages.single.html, '<p>Hi</p>');
  });

  test('file bytes are served from memory after the first save', () async {
    final bytes = Uint8List.fromList([1, 2, 3, 4]);
    await cache.writeBytes('https://maildesk.ng/file/1', bytes);

    expect(cache.readBytes('https://maildesk.ng/file/1'), bytes);
    expect(cache.readBytes('https://maildesk.ng/missing'), isNull);
  });
}
