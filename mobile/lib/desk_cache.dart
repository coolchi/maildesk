import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:maildesk/models.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Last inbox, chats, letters, and files, so a screen can paint before the network answers.
class DeskCache {
  DeskCache(this._prefs, [Directory? root]) : _root = root;

  static DeskCache? current;

  static const _keptLetters = 8;
  static const _keptChats = 8;
  static const _keptMessages = 80;
  static const _byteBudget = 40 * 1024 * 1024;
  static const _maxFile = 6 * 1024 * 1024;

  final SharedPreferences _prefs;
  Directory? _root;
  final _memoryBytes = <String, Uint8List>{};

  static Future<DeskCache> open() async {
    final cache = DeskCache(await SharedPreferences.getInstance());
    unawaited(cache._attach());
    return cache;
  }

  Future<void> _attach() async {
    if (_root != null) {
      return;
    }
    try {
      final support = await getApplicationSupportDirectory();
      final root = Directory('${support.path}/desk_cache');
      root.createSync(recursive: true);
      _root = root;
    } catch (_) {
      _root = await Directory.systemTemp.createTemp('desk_cache');
    }
  }

  Map<String, dynamic>? readAccount() => _jsonMap(_prefs.getString('desk.account'));

  Future<void> saveAccount(Map<String, dynamic> json) {
    return _prefs.setString('desk.account', jsonEncode(json));
  }

  ({List<MailThread> threads, List<ConversationSummary> conversations})? readLists(int organizationId) {
    final json = _jsonMap(_prefs.getString('desk.lists.$organizationId'));
    if (json == null) {
      return null;
    }
    return (
      threads: _models(json['threads'], MailThread.fromJson),
      conversations: _models(json['conversations'], ConversationSummary.fromJson),
    );
  }

  Future<void> saveLists({
    required int organizationId,
    required List<MailThread> threads,
    required List<ConversationSummary> conversations,
  }) {
    return _prefs.setString(
      'desk.lists.$organizationId',
      jsonEncode({
        'threads': threads.map((item) => item.toJson()).toList(),
        'conversations': conversations.map((item) => item.toJson()).toList(),
      }),
    );
  }

  MailDetail? readMail(int organizationId, int threadId) {
    final root = _root;
    if (root == null) {
      return null;
    }
    final file = File('${root.path}/mail/$organizationId/$threadId.json');
    if (!file.existsSync()) {
      return null;
    }
    final json = _jsonMap(file.readAsStringSync());
    if (json == null) {
      return null;
    }
    return MailDetail.fromJson(json);
  }

  Future<void> saveMail(int organizationId, int threadId, MailDetail detail) async {
    final root = _root;
    final encoded = jsonEncode(detail.toJson());
    if (root == null || encoded.length > 400000) {
      return;
    }
    final dir = Directory('${root.path}/mail/$organizationId');
    dir.createSync(recursive: true);
    await File('${dir.path}/$threadId.json').writeAsString(encoded);
    await _touchOrder(dir, '$threadId', _keptLetters);
  }

  Map<int, List<ChatMessage>> readMessages(int organizationId) {
    final root = _root;
    if (root == null) {
      return {};
    }
    final dir = Directory('${root.path}/chat/$organizationId');
    final order = _order(dir);
    final loaded = <int, List<ChatMessage>>{};
    for (final name in order) {
      final id = int.tryParse(name);
      final file = File('${dir.path}/$name.json');
      if (id == null || !file.existsSync()) {
        continue;
      }
      final json = jsonDecode(file.readAsStringSync());
      if (json is! List) {
        continue;
      }
      loaded[id] = json
          .whereType<Map>()
          .map((item) => ChatMessage.fromJson(Map<String, dynamic>.from(item)))
          .toList();
    }
    return loaded;
  }

  Future<void> saveMessages(int organizationId, int conversationId, List<ChatMessage> messages) async {
    final root = _root;
    final kept = messages.where((item) => !item.pending).toList();
    final slice = kept.length <= _keptMessages ? kept : kept.sublist(kept.length - _keptMessages);
    if (root == null) {
      return;
    }
    final dir = Directory('${root.path}/chat/$organizationId');
    dir.createSync(recursive: true);
    await File('${dir.path}/$conversationId.json').writeAsString(jsonEncode(slice.map((item) => item.toJson()).toList()));
    await _touchOrder(dir, '$conversationId', _keptChats);
  }

  Uint8List? readBytes(String url) {
    final memory = _memoryBytes.remove(url);
    if (memory != null) {
      _memoryBytes[url] = memory;
      return memory;
    }
    final hit = _byteIndex().where((item) => item['url'] == url).firstOrNull;
    if (hit == null) {
      return null;
    }
    final dir = _bytesDir;
    if (dir == null) {
      return null;
    }
    final file = File('${dir.path}/${hit['file']}');
    if (!file.existsSync()) {
      return null;
    }
    final bytes = file.readAsBytesSync();
    _rememberBytes(url, bytes);
    return bytes;
  }

  Future<void> writeBytes(String url, Uint8List bytes) async {
    if (bytes.isEmpty || bytes.length > _maxFile) {
      return;
    }
    _rememberBytes(url, bytes);
    final dir = _bytesDir;
    if (dir == null) {
      return;
    }
    dir.createSync(recursive: true);
    final name = _fileKey(url);
    await File('${dir.path}/$name').writeAsBytes(bytes, flush: true);
    final index = _byteIndex().where((item) => item['url'] != url).toList();
    index.add({'url': url, 'file': name, 'size': bytes.length, 'at': DateTime.now().millisecondsSinceEpoch});
    var total = index.fold<int>(0, (sum, item) => sum + (item['size'] as int? ?? 0));
    index.sort((a, b) => (a['at'] as int? ?? 0).compareTo(b['at'] as int? ?? 0));
    while (total > _byteBudget && index.isNotEmpty) {
      final oldest = index.removeAt(0);
      total -= oldest['size'] as int? ?? 0;
      final file = File('${dir.path}/${oldest['file']}');
      if (file.existsSync()) {
        file.deleteSync();
      }
    }
    await File('${dir.path}/index.json').writeAsString(jsonEncode(index));
  }

  Future<void> clear() async {
    _memoryBytes.clear();
    for (final key in _prefs.getKeys().where((key) => key.startsWith('desk.')).toList()) {
      await _prefs.remove(key);
    }
    final root = _root;
    if (root != null && root.existsSync()) {
      root.deleteSync(recursive: true);
      root.createSync(recursive: true);
    }
  }

  Directory? get _bytesDir {
    final root = _root;
    if (root == null) {
      return null;
    }
    return Directory('${root.path}/bytes');
  }

  void _rememberBytes(String url, Uint8List bytes) {
    _memoryBytes.remove(url);
    _memoryBytes[url] = bytes;
    while (_memoryBytes.length > 32) {
      _memoryBytes.remove(_memoryBytes.keys.first);
    }
  }

  List<Map<String, dynamic>> _byteIndex() {
    final dir = _bytesDir;
    if (dir == null) {
      return [];
    }
    final file = File('${dir.path}/index.json');
    if (!file.existsSync()) {
      return [];
    }
    final json = jsonDecode(file.readAsStringSync());
    if (json is! List) {
      return [];
    }
    return json.whereType<Map>().map((item) => Map<String, dynamic>.from(item)).toList();
  }

  List<String> _order(Directory dir) {
    final file = File('${dir.path}/order.json');
    if (!file.existsSync()) {
      return [];
    }
    final json = jsonDecode(file.readAsStringSync());
    if (json is! List) {
      return [];
    }
    return json.map((item) => item.toString()).toList();
  }

  Future<void> _touchOrder(Directory dir, String id, int keep) async {
    final order = [id, ..._order(dir).where((item) => item != id)];
    final extra = order.length > keep ? order.sublist(keep) : <String>[];
    final kept = order.length > keep ? order.sublist(0, keep) : order;
    for (final name in extra) {
      final file = File('${dir.path}/$name.json');
      if (file.existsSync()) {
        file.deleteSync();
      }
    }
    await File('${dir.path}/order.json').writeAsString(jsonEncode(kept));
  }
}

Map<String, dynamic>? _jsonMap(String? raw) {
  if (raw == null || raw.isEmpty) {
    return null;
  }
  final json = jsonDecode(raw);
  if (json is! Map) {
    return null;
  }
  return Map<String, dynamic>.from(json);
}

List<T> _models<T>(Object? json, T Function(Map<String, dynamic>) decode) {
  if (json is! List) {
    return [];
  }
  return json.whereType<Map>().map((item) => decode(Map<String, dynamic>.from(item))).toList();
}

String _fileKey(String url) {
  var hash = 0xcbf29ce484222325;
  for (final unit in url.codeUnits) {
    hash ^= unit;
    hash = (hash * 0x100000001b3) & 0xFFFFFFFFFFFFFFFF;
  }
  return hash.toRadixString(16);
}

bool sameMailThreads(List<MailThread> left, List<MailThread> right) {
  return jsonEncode(left.map((item) => item.toJson()).toList()) == jsonEncode(right.map((item) => item.toJson()).toList());
}

bool sameConversations(List<ConversationSummary> left, List<ConversationSummary> right) {
  return jsonEncode(left.map((item) => item.toJson()).toList()) == jsonEncode(right.map((item) => item.toJson()).toList());
}
