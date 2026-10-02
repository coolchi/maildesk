import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;
import 'package:maildesk/desk_cache.dart';
import 'package:maildesk/models.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.status});

  final String message;
  final int? status;

  @override
  String toString() => message;
}

class MailDeskApi {
  MailDeskApi({
    required this.baseUrl,
    this.token,
    this.organizationId,
  });

  /// Called when a signed-in request comes back as 401.
  static void Function()? onUnauthorized;

  final String baseUrl;
  final String? token;
  final int? organizationId;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        if (organizationId != null) 'X-Organization-Id': '$organizationId',
      };

  Future<Map<String, dynamic>> login({
    required String email,
    required String password,
    required String deviceName,
  }) {
    return _send('POST', '/api/app/login', {
      'email': email,
      'password': password,
      'device_name': deviceName,
    });
  }

  Future<Map<String, dynamic>> me() => _send('GET', '/api/app/me');

  Future<void> logout() => _send('POST', '/api/app/logout');

  Future<List<Person>> members() async {
    final json = await _send('GET', '/api/app/members');
    return _list(json).map(Person.fromJson).toList();
  }

  Future<List<ConversationSummary>> conversations() async {
    final json = await _send('GET', '/api/app/conversations');
    return _list(json).map(ConversationSummary.fromJson).toList();
  }

  Future<ConversationSummary> openConversation({
    required String type,
    required List<int> userIds,
    String? name,
  }) async {
    final json = await _send('POST', '/api/app/conversations', {
      'type': type,
      'user_ids': userIds,
      'name': ?name,
    });
    return ConversationSummary.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<({ConversationSummary conversation, List<ChatMessage> messages})> conversation(int id) async {
    final json = await _send('GET', '/api/app/conversations/$id');
    return (
      conversation: ConversationSummary.fromJson(json['data'] as Map<String, dynamic>),
      messages: ((json['messages'] as List?) ?? [])
          .map((item) => ChatMessage.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }

  Future<ChatMessage> sendMessage(int conversationId, String body) {
    return sendChat(conversationId, body: body);
  }

  Future<ChatMessage> sendChat(
    int conversationId, {
    String body = '',
    String? filePath,
    String kind = 'text',
    int? durationMs,
  }) async {
    final path = '/api/app/conversations/$conversationId/messages';
    if (filePath == null) {
      final json = await _send('POST', path, {'body': body, 'kind': kind});
      return ChatMessage.fromJson(json['data'] as Map<String, dynamic>);
    }
    final json = await _upload(path, files: [filePath], fileField: 'file', fields: {
      'body': body,
      'kind': kind,
      if (durationMs != null) 'duration_ms': '$durationMs',
    });
    return ChatMessage.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<ConversationSummary> pinConversation(int id, bool pinned) async {
    final json = await _send('POST', '/api/app/conversations/$id/pin', {'pinned': pinned});
    return ConversationSummary.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<void> typing(int conversationId) => _send('POST', '/api/app/conversations/$conversationId/typing');

  Future<void> deleteMessage(int conversationId, int messageId) {
    return _send('DELETE', '/api/app/conversations/$conversationId/messages/$messageId');
  }

  Future<List<MailThread>> inbox({String folder = 'inbox', String query = ''}) async {
    final params = <String, String>{'folder': folder};
    if (query.trim().isNotEmpty) {
      params['q'] = query.trim();
    }
    final json = await _send('GET', '/api/app/inbox/threads?${Uri(queryParameters: params).query}');
    return _list(json).map(MailThread.fromJson).toList();
  }

  Future<MailDetail> mail(int id) async {
    final json = await _send('GET', '/api/app/inbox/threads/$id');
    return MailDetail.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<void> mailAction(int id, String action) => _send('POST', '/api/app/inbox/threads/$id/$action');

  Future<void> markInboxRead() => _send('POST', '/api/app/inbox/read');

  Future<void> markChatRead(int id) => _send('POST', '/api/app/conversations/$id/read');

  Future<void> markDelivered(int id) => _send('POST', '/api/app/conversations/$id/delivered');

  Future<void> reply(int id, String body, {List<String> files = const []}) async {
    final path = '/api/app/inbox/threads/$id/reply';
    if (files.isEmpty) {
      await _send('POST', path, {'body': body});
      return;
    }
    await _upload(path, files: files, fields: {'body': body});
  }

  Future<void> forward(int id, {required String to, String body = ''}) {
    return _send('POST', '/api/app/inbox/threads/$id/forward', {'to': to, 'body': body});
  }

  Future<void> compose({
    required String to,
    required String subject,
    required String body,
    String? cc,
    String? bcc,
    List<String> files = const [],
  }) async {
    final fields = {
      'to': to,
      'subject': subject,
      'body': body,
      if (cc != null && cc.isNotEmpty) 'cc': cc,
      if (bcc != null && bcc.isNotEmpty) 'bcc': bcc,
    };
    if (files.isEmpty) {
      await _send('POST', '/api/app/inbox/send', fields);
      return;
    }
    await _upload('/api/app/inbox/send', files: files, fields: fields);
  }

  Future<AiCapabilities> ai() async {
    final json = await _send('GET', '/api/app/ai');
    return AiCapabilities.fromJson(json);
  }

  Future<ComposeAssist> composeAssist({
    required String action,
    String body = '',
    String subject = '',
    String? tone,
  }) async {
    final json = await _send('POST', '/api/app/ai/compose', {
      'action': action,
      'body': body,
      'subject': subject,
      'tone': ?tone,
    });
    return ComposeAssist.fromJson(json);
  }

  Future<String> suggestReply(int threadId) async {
    final json = await _send('POST', '/api/app/inbox/threads/$threadId/suggest');
    return json['text'] as String? ?? '';
  }

  Future<ThreadSummary> summarize(int threadId) async {
    final json = await _send('POST', '/api/app/inbox/threads/$threadId/summarize');
    return ThreadSummary.fromJson(json);
  }

  Future<List<MailDraft>> drafts() async {
    final json = await _send('GET', '/api/app/inbox/drafts');
    return _list(json).map(MailDraft.fromJson).toList();
  }

  Future<void> saveDraft({int? id, String? to, String? cc, String? bcc, String? subject, String? body}) {
    return _send('POST', '/api/app/inbox/drafts', {
      'id': ?id,
      'to': to,
      'cc': cc,
      'bcc': bcc,
      'subject': subject,
      'body': body,
    });
  }

  Future<void> deleteDraft(int id) => _send('DELETE', '/api/app/inbox/drafts/$id');

  Future<void> addContact({required String email, String? name, String? company}) {
    return _send('POST', '/api/app/contacts', {
      'email': email,
      'name': name,
      'company': company,
    });
  }

  Future<Uint8List> fetchBytes(String url) async {
    final cached = DeskCache.current?.readBytes(url);
    if (cached != null) {
      return cached;
    }
    late http.Response response;
    try {
      response = await http.get(Uri.parse(url), headers: {
        'Accept': '*/*',
        if (token != null) 'Authorization': 'Bearer $token',
        if (organizationId != null) 'X-Organization-Id': '$organizationId',
      }).timeout(const Duration(seconds: 30));
    } catch (_) {
      throw ApiException('Could not reach MailDesk. Check the server address.');
    }
    if (response.statusCode >= 200 && response.statusCode < 300) {
      final bytes = response.bodyBytes;
      await DeskCache.current?.writeBytes(url, bytes);
      return bytes;
    }
    _rejectIfUnauthenticated(response.statusCode);
    throw ApiException('Could not open this file.', status: response.statusCode);
  }

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final uri = Uri.parse('$baseUrl$path');
    late http.Response response;
    try {
      response = await switch (method) {
        'POST' => http.post(uri, headers: _headers, body: body == null ? null : jsonEncode(body)),
        'DELETE' => http.delete(uri, headers: _headers),
        _ => http.get(uri, headers: _headers),
      }.timeout(const Duration(seconds: 20));
    } catch (_) {
      throw ApiException('Could not reach MailDesk. Check the server address.');
    }

    final decoded = response.body.isEmpty ? <String, dynamic>{} : _object(response.body);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    _rejectIfUnauthenticated(response.statusCode);
    throw ApiException(_message(decoded), status: response.statusCode);
  }

  Future<Map<String, dynamic>> _upload(
    String path, {
    List<String> files = const [],
    String fileField = 'files[]',
    Map<String, String> fields = const {},
  }) async {
    final request = http.MultipartRequest('POST', Uri.parse('$baseUrl$path'));
    request.headers.addAll({
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
      if (organizationId != null) 'X-Organization-Id': '$organizationId',
    });
    request.fields.addAll(fields);
    for (final file in files) {
      request.files.add(await http.MultipartFile.fromPath(fileField, file));
    }
    late http.Response response;
    try {
      final streamed = await request.send().timeout(const Duration(seconds: 40));
      response = await http.Response.fromStream(streamed);
    } catch (_) {
      throw ApiException('Could not reach MailDesk. Check the server address.');
    }
    final decoded = response.body.isEmpty ? <String, dynamic>{} : _object(response.body);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }
    _rejectIfUnauthenticated(response.statusCode);
    throw ApiException(_message(decoded), status: response.statusCode);
  }

  void _rejectIfUnauthenticated(int status) {
    if (status == 401 && token != null) {
      onUnauthorized?.call();
    }
  }

  Map<String, dynamic> _object(String body) {
    try {
      final decoded = jsonDecode(body);
      if (decoded is Map<String, dynamic>) {
        return decoded;
      }
      return {};
    } on FormatException {
      throw ApiException('MailDesk sent a page instead of data. Check the server address.');
    }
  }

  List<Map<String, dynamic>> _list(Map<String, dynamic> json) {
    return ((json['data'] as List?) ?? []).cast<Map<String, dynamic>>();
  }

  String _message(Map<String, dynamic> json) {
    final errors = json['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) {
        return first.first.toString();
      }
    }
    return json['message'] as String? ?? 'Something went wrong.';
  }
}
