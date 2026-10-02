import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/desk_cache.dart';
import 'package:maildesk/outbox.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late Directory root;
  late DeskCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    root = await Directory.systemTemp.createTemp('outbox_test');
    cache = DeskCache(await SharedPreferences.getInstance(), root);
  });

  tearDown(() {
    if (root.existsSync()) {
      root.deleteSync(recursive: true);
    }
  });

  test('outbox items round-trip on disk', () async {
    final item = OutboxItem(
      id: 'outbox-1',
      kind: OutboxKind.compose,
      organizationId: 7,
      createdAt: '2026-10-02T05:00:00.000',
      to: 'ada@example.com',
      subject: 'Hello',
      body: 'Hi Ada',
      files: const ['/tmp/a.pdf'],
    );

    await cache.saveOutbox(7, [item]);
    final loaded = cache.readOutbox(7);

    expect(loaded, hasLength(1));
    expect(loaded.single.to, 'ada@example.com');
    expect(loaded.single.subject, 'Hello');
    expect(loaded.single.kind, OutboxKind.compose);
    expect(loaded.single.files, ['/tmp/a.pdf']);
    expect(cache.readOutbox(8), isEmpty);
  });

  test('network failures are treated as offline', () {
    expect(isOfflineError(ApiException('Could not reach MailDesk. Check the server address.')), isTrue);
    expect(isOfflineError(ApiException('Not found', status: 404)), isFalse);
    expect(isOfflineError(Exception('nope')), isFalse);
  });

  test('outbox labels describe what is waiting', () {
    expect(
      const OutboxItem(
        id: '1',
        kind: OutboxKind.compose,
        organizationId: 1,
        createdAt: '',
        subject: 'Invoice',
      ).label,
      'Waiting to send · Invoice',
    );
    expect(
      const OutboxItem(
        id: '2',
        kind: OutboxKind.draft,
        organizationId: 1,
        createdAt: '',
        subject: '',
      ).label,
      'Offline draft · (no subject)',
    );
  });
}
