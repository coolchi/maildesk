import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:maildesk/alerts.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/desk_cache.dart';
import 'package:maildesk/live.dart';
import 'package:maildesk/models.dart';
import 'package:shared_preferences/shared_preferences.dart';

class Session extends ChangeNotifier {
  bool ready = false;
  bool busy = false;
  String baseUrl = 'https://maildesk.ng';
  String? token;
  Person? user;
  List<Workspace> workspaces = [];
  Workspace? workspace;
  RealtimeConfig? realtime;
  List<ConversationSummary> conversations = [];
  List<MailThread> threads = [];
  String? error;
  int? errorStatus;
  String? theme;
  String inboxTitle = 'Inbox';
  int? openConversationId;
  bool inboxVisible = false;
  int? typingConversationId;
  String? typingName;

  final Map<int, List<ChatMessage>> messages = {};
  final Map<int, MailDetail> mailCache = {};
  DeskCache? _cache;
  final Set<String> _receiptAck = {};
  final alerts = Alerts();
  LiveConnection? _live;
  Timer? _poll;
  Timer? _typingTimer;
  var _expiring = false;

  Session() {
    MailDeskApi.onUnauthorized = expire;
  }

  MailDeskApi get api => MailDeskApi(
        baseUrl: baseUrl,
        token: token,
        organizationId: workspace?.id,
      );

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    _cache = await DeskCache.open();
    DeskCache.current = _cache;
    baseUrl = prefs.getString('base_url') ?? baseUrl;
    theme = prefs.getString('theme');
    token = prefs.getString('token');
    final organizationId = prefs.getInt('organization_id');
    if (token != null) {
      _showCachedAccount();
      if (organizationId != null) {
        _showCachedWorkspace(organizationId);
      }
      if (workspace != null) {
        ready = true;
        notifyListeners();
        unawaited(_resume(organizationId));
        return;
      }
      try {
        await _loadAccount();
        final match = workspaces.where((item) => item.id == organizationId);
        if (match.isNotEmpty) {
          _showCachedWorkspace(match.first.id);
          workspace = match.first;
          ready = true;
          notifyListeners();
          unawaited(chooseWorkspace(match.first, remember: false));
          return;
        }
      } on ApiException {
        await _clearAuth();
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<void> _resume(int? organizationId) async {
    try {
      await _loadAccount();
      final match = workspaces.where((item) => item.id == organizationId);
      if (match.isEmpty) {
        return;
      }
      await chooseWorkspace(match.first, remember: false);
    } on ApiException {
      await expire();
    }
  }

  Future<void> setTheme(String value) async {
    if (theme == value) {
      return;
    }
    theme = value;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('theme', value);
    notifyListeners();
  }

  Future<void> signIn({
    required String email,
    required String password,
    required String server,
  }) async {
    busy = true;
    error = null;
    errorStatus = null;
    notifyListeners();
    baseUrl = _normalize(server);
    try {
      final json = await MailDeskApi(baseUrl: baseUrl).login(
        email: email.trim(),
        password: password,
        deviceName: Platform.isIOS ? 'iPhone' : 'Android',
      );
      token = json['token'] as String;
      _applyAccount(json);
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('base_url', baseUrl);
      await prefs.setString('token', token!);
      if (workspaces.length == 1) {
        await chooseWorkspace(workspaces.first);
      }
    } on ApiException catch (exception) {
      error = exception.message;
    } catch (_) {
      error = 'Could not sign in. Check the server address.';
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> chooseWorkspace(Workspace next, {bool remember = true}) async {
    final switching = workspace?.id != next.id;
    workspace = next;
    if (switching) {
      _showCachedWorkspace(next.id);
    }
    if (remember) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('organization_id', next.id);
    }
    notifyListeners();
    await refresh();
    await _connect();
    notifyListeners();
  }

  Future<void> refresh() async {
    try {
      final nextConversations = await api.conversations();
      final nextThreads = await api.inbox();
      final changed = error != null || !sameConversations(conversations, nextConversations) || !sameMailThreads(threads, nextThreads);
      conversations = nextConversations;
      threads = nextThreads;
      error = null;
      errorStatus = null;
      final organizationId = workspace?.id;
      if (organizationId != null) {
        unawaited(_cache?.saveLists(organizationId: organizationId, threads: threads, conversations: conversations));
      }
      if (changed) {
        notifyListeners();
      }
      unawaited(_ackReceipts());
    } on ApiException catch (exception) {
      if (exception.status == 401) {
        await expire();
        return;
      }
      error = exception.message;
      errorStatus = exception.status;
      notifyListeners();
    }
  }

  void rememberMessages(int conversationId, List<ChatMessage> next) {
    messages[conversationId] = next;
    final organizationId = workspace?.id;
    if (organizationId != null) {
      unawaited(_cache?.saveMessages(organizationId, conversationId, next));
    }
  }

  void rememberConversation(ConversationSummary conversation) {
    final index = conversations.indexWhere((item) => item.id == conversation.id);
    if (index == -1) {
      conversations = [conversation, ...conversations];
    } else {
      conversations = [
        for (final item in conversations)
          if (item.id == conversation.id) conversation else item,
      ];
    }
    final organizationId = workspace?.id;
    if (organizationId != null) {
      unawaited(_cache?.saveLists(organizationId: organizationId, threads: threads, conversations: conversations));
    }
    notifyListeners();
  }

  MailDetail? storedMail(int threadId) {
    final memory = mailCache[threadId];
    if (memory != null) {
      return memory;
    }
    final organizationId = workspace?.id;
    if (organizationId == null) {
      return null;
    }
    final stored = _cache?.readMail(organizationId, threadId);
    if (stored != null) {
      mailCache[threadId] = stored;
    }
    return stored;
  }

  Future<void> rememberMail(int threadId, MailDetail detail) async {
    mailCache[threadId] = detail;
    final organizationId = workspace?.id;
    if (organizationId != null) {
      await _cache?.saveMail(organizationId, threadId, detail);
    }
  }

  /// Drop a dead session so the app returns to the login screen.
  Future<void> expire() async {
    if (token == null || _expiring) {
      return;
    }
    _expiring = true;
    await _clearAuth();
    ready = true;
    _expiring = false;
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await api.logout();
    } catch (_) {}
    await _clearAuth();
    ready = true;
    notifyListeners();
  }

  void setOpenConversation(int? id) {
    openConversationId = id;
    if (id != null) {
      _live?.watch('private-conversations.$id');
      conversations = [
        for (final conversation in conversations)
          if (conversation.id == id) conversation.copyWith(unreadCount: 0) else conversation,
      ];
      notifyListeners();
    }
  }

  void setInboxTitle(String title) {
    if (inboxTitle == title) {
      return;
    }
    inboxTitle = title;
    notifyListeners();
  }

  void setInboxVisible(bool visible) {
    inboxVisible = visible;
  }

  void setThreads(List<MailThread> next) {
    threads = next;
    final organizationId = workspace?.id;
    if (organizationId != null) {
      unawaited(_cache?.saveLists(organizationId: organizationId, threads: threads, conversations: conversations));
    }
    notifyListeners();
  }

  void stageMessage(ChatMessage message) {
    final current = messages[message.conversationId] ?? [];
    messages[message.conversationId] = [...current, message];
    notifyListeners();
  }

  void dropMessage(int conversationId, int messageId) {
    final current = messages[conversationId];
    if (current == null) {
      return;
    }
    final removedWasLatest = current.isNotEmpty && current.last.id == messageId;
    final next = [for (final item in current) if (item.id != messageId) item];
    rememberMessages(conversationId, next);
    if (removedWasLatest) {
      final index = conversations.indexWhere((item) => item.id == conversationId);
      if (index != -1 && next.isNotEmpty) {
        final latest = next.last;
        conversations = [
          for (final conversation in conversations)
            if (conversation.id != conversationId)
              conversation
            else
              conversation.copyWith(preview: latest.listPreview, lastMessageAt: latest.createdAt),
        ];
        _sortChats();
      } else if (next.isEmpty) {
        refresh();
      }
    }
    notifyListeners();
  }

  Future<ChatMessage> send(int conversationId, String body) async {
    final message = await api.sendMessage(conversationId, body);
    _applyMessage(message, notify: false);
    return message;
  }

  Future<ChatMessage> sendFile(
    int conversationId, {
    required String path,
    String body = '',
    required String kind,
    int? durationMs,
  }) async {
    final message = await api.sendChat(conversationId, body: body, filePath: path, kind: kind, durationMs: durationMs);
    _applyMessage(message, notify: false);
    return message;
  }

  Future<void> pin(ConversationSummary conversation) async {
    final updated = await api.pinConversation(conversation.id, !conversation.pinned);
    conversations = [
      for (final item in conversations)
        if (item.id == conversation.id) updated else item,
    ];
    _sortChats();
    notifyListeners();
  }

  Future<void> pulseTyping(int conversationId) => api.typing(conversationId);

  void onLive(String event, Map<String, dynamic> data) {
    if (event == 'chat.message' && data['message'] is Map<String, dynamic>) {
      final message = ChatMessage.fromJson(data['message'] as Map<String, dynamic>);
      final mine = message.userId != null && message.userId == user?.id;
      _applyMessage(message, notify: !mine && openConversationId != message.conversationId);
      if (!mine) {
        final open = openConversationId == message.conversationId;
        _receiptAck.add('${message.conversationId}:${open ? 'read' : 'delivered'}:${message.createdAt ?? ''}');
        unawaited(_ack(open, message.conversationId));
      }
      return;
    }
    if (event == 'chat.deleted') {
      final conversationId = data['conversation_id'] as int?;
      final messageId = data['message_id'] as int?;
      if (conversationId != null && messageId != null) {
        dropMessage(conversationId, messageId);
      }
      return;
    }
    if (event == 'chat.typing') {
      final conversationId = data['conversation_id'] as int?;
      final userId = data['user_id'] as int?;
      if (conversationId == null || userId == user?.id || conversationId != openConversationId) {
        return;
      }
      typingConversationId = conversationId;
      typingName = data['name'] as String? ?? 'Someone';
      notifyListeners();
      _typingTimer?.cancel();
      _typingTimer = Timer(const Duration(seconds: 3), () {
        typingName = null;
        notifyListeners();
      });
      return;
    }
    if (event == 'chat.read') {
      _markRead(data['conversation_id'] as int?, data['user_id'] as int?, data['read_at'] as String?);
      return;
    }
    if (event == 'chat.delivered') {
      _markDelivered(data['conversation_id'] as int?, data['user_id'] as int?, data['delivered_at'] as String?);
      return;
    }
    if (event == 'inbox.updated') {
      final before = threads.where((thread) => thread.unread).length;
      refresh().then((_) {
        final after = threads.where((thread) => thread.unread).length;
        if (!inboxVisible && after > before) {
          alerts.show(id: 1, title: workspace?.name ?? 'MailDesk', body: 'New mail');
        }
      });
    }
  }

  Future<void> _loadAccount() async {
    _applyAccount(await MailDeskApi(baseUrl: baseUrl, token: token).me());
  }

  void _applyAccount(Map<String, dynamic> json) {
    final account = json['user'] as Map<String, dynamic>;
    user = Person(id: account['id'] as int, name: account['name'] as String, email: account['email'] as String?);
    workspaces = ((json['organizations'] as List?) ?? [])
        .map((item) => Workspace.fromJson(item as Map<String, dynamic>))
        .toList();
    realtime = RealtimeConfig.fromJson(json['realtime'] as Map<String, dynamic>? ?? {});
    unawaited(_cache?.saveAccount(json));
  }

  void _showCachedAccount() {
    final account = _cache?.readAccount();
    if (account == null || account['user'] is! Map) {
      return;
    }
    _applyAccount(account);
  }

  void _showCachedWorkspace(int organizationId) {
    final match = workspaces.where((item) => item.id == organizationId);
    if (match.isNotEmpty) {
      workspace = match.first;
    }
    final lists = _cache?.readLists(organizationId);
    threads = lists?.threads ?? [];
    conversations = lists?.conversations ?? [];
    messages
      ..clear()
      ..addAll(_cache?.readMessages(organizationId) ?? {});
    mailCache.clear();
  }

  Future<void> _ack(bool open, int conversationId) async {
    try {
      if (open) {
        await api.markChatRead(conversationId);
      } else {
        await api.markDelivered(conversationId);
      }
    } on ApiException {
      // A missed receipt can be sent again. It should not surface as a send failure.
    }
  }

  void _applyMessage(ChatMessage message, {required bool notify}) {
    final current = messages[message.conversationId] ?? [];
    if (current.any((item) => item.id == message.id)) {
      return;
    }
    final pendingIndex = current.indexWhere(
      (item) => item.pending && item.userId == message.userId && item.body == message.body && item.kind == message.kind,
    );
    final withoutPending = pendingIndex < 0 ? current : ([...current]..removeAt(pendingIndex));
    messages[message.conversationId] = [...withoutPending, message];
    rememberMessages(message.conversationId, messages[message.conversationId]!);
    final index = conversations.indexWhere((item) => item.id == message.conversationId);
    if (index == -1) {
      refresh();
    } else {
      final existing = conversations[index];
      final open = openConversationId == message.conversationId;
      conversations = [
        existing.copyWith(
          preview: message.listPreview,
          lastMessageAt: message.createdAt,
          unreadCount: open || message.userId == user?.id ? 0 : existing.unreadCount + 1,
        ),
        ...conversations.where((item) => item.id != message.conversationId),
      ];
      _sortChats();
    }
    if (notify) {
      alerts.show(
        id: message.id,
        title: message.userName,
        body: message.listPreview,
      );
    }
    notifyListeners();
  }

  void _sortChats() {
    conversations.sort((left, right) {
      if (left.pinned != right.pinned) {
        return left.pinned ? -1 : 1;
      }
      return (right.lastMessageAt ?? '').compareTo(left.lastMessageAt ?? '');
    });
  }

  void _markRead(int? conversationId, int? userId, String? readAt) {
    if (conversationId == null || userId == null) {
      return;
    }
    conversations = [
      for (final conversation in conversations)
        if (conversation.id != conversationId)
          conversation
        else
          conversation.copyWith(
            participants: [
              for (final participant in conversation.participants)
                if (participant.id == userId) participant.copyWith(lastReadAt: readAt, lastDeliveredAt: readAt) else participant,
            ],
          ),
    ];
    notifyListeners();
  }

  void _markDelivered(int? conversationId, int? userId, String? deliveredAt) {
    if (conversationId == null || userId == null) {
      return;
    }
    conversations = [
      for (final conversation in conversations)
        if (conversation.id != conversationId)
          conversation
        else
          conversation.copyWith(
            participants: [
              for (final participant in conversation.participants)
                if (participant.id == userId) participant.copyWith(lastDeliveredAt: deliveredAt) else participant,
            ],
          ),
    ];
    notifyListeners();
  }

  Future<void> _ackReceipts() async {
    final pending = [
      for (final conversation in conversations)
        if (openConversationId == conversation.id || conversation.unreadCount > 0) conversation,
    ];
    for (final conversation in pending) {
      final open = openConversationId == conversation.id;
      final key = '${conversation.id}:${open ? 'read' : 'delivered'}:${conversation.lastMessageAt ?? ''}';
      if (!_receiptAck.add(key)) {
        continue;
      }
      try {
        if (open) {
          await api.markChatRead(conversation.id);
        } else {
          await api.markDelivered(conversation.id);
        }
      } on ApiException {
        _receiptAck.remove(key);
      }
    }
  }

  Future<void> _connect() async {
    await _live?.disconnect();
    _poll?.cancel();
    final config = realtime;
    final auth = token;
    final organizationId = workspace?.id;
    final userId = user?.id;
    if (config == null || auth == null || organizationId == null || userId == null) {
      return;
    }
    await alerts.start();
    final live = LiveConnection(config: config, token: auth, onEvent: onLive);
    _live = live;
    await live.connect([
      'private-users.$userId.chat',
      'private-organizations.$organizationId.inbox',
    ]);
    _poll = Timer.periodic(const Duration(seconds: 12), (_) => refresh());
  }

  Future<void> _clearAuth() async {
    await _live?.disconnect();
    _poll?.cancel();
    token = null;
    user = null;
    workspace = null;
    workspaces = [];
    conversations = [];
    threads = [];
    messages.clear();
    mailCache.clear();
    _receiptAck.clear();
    unawaited(_cache?.clear());
    error = null;
    errorStatus = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('token');
    await prefs.remove('organization_id');
  }

  String _normalize(String value) => normalizeServerUrl(value);

  @override
  void dispose() {
    _poll?.cancel();
    _typingTimer?.cancel();
    _live?.disconnect();
    super.dispose();
  }
}

/// Public MailDesk hosts redirect plain HTTP to an HTML page, which breaks sign-in.
/// Local addresses stay on HTTP.
String normalizeServerUrl(String value) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) {
    return 'https://maildesk.ng';
  }
  var withScheme = trimmed.contains('://') ? trimmed : 'https://$trimmed';
  if (withScheme.endsWith('/')) {
    withScheme = withScheme.substring(0, withScheme.length - 1);
  }
  final uri = Uri.tryParse(withScheme);
  if (uri == null || uri.host.isEmpty || uri.scheme != 'http' || _keepsPlainHttp(uri.host)) {
    return withScheme;
  }

  return uri.replace(scheme: 'https').removeFragment().toString();
}

bool _keepsPlainHttp(String host) {
  final name = host.toLowerCase();
  if (name == 'localhost' || name == '127.0.0.1' || name == '::1' || name.endsWith('.test') || name.endsWith('.local')) {
    return true;
  }
  final address = InternetAddress.tryParse(name);
  if (address == null) {
    return false;
  }
  final bytes = address.rawAddress;
  if (bytes.length != 4) {
    return false;
  }
  if (bytes[0] == 10 || bytes[0] == 127) {
    return true;
  }
  if (bytes[0] == 192 && bytes[1] == 168) {
    return true;
  }
  return bytes[0] == 172 && bytes[1] >= 16 && bytes[1] <= 31;
}
