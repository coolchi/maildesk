import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:maildesk/alerts.dart';
import 'package:maildesk/api.dart';
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
  final Set<String> _receiptAck = {};
  final alerts = Alerts();
  LiveConnection? _live;
  Timer? _poll;
  Timer? _typingTimer;

  MailDeskApi get api => MailDeskApi(
        baseUrl: baseUrl,
        token: token,
        organizationId: workspace?.id,
      );

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    baseUrl = prefs.getString('base_url') ?? baseUrl;
    theme = prefs.getString('theme');
    token = prefs.getString('token');
    final organizationId = prefs.getInt('organization_id');
    if (token != null) {
      try {
        await _loadAccount();
        final match = workspaces.where((item) => item.id == organizationId);
        if (match.isNotEmpty) {
          await chooseWorkspace(match.first, remember: false);
        }
      } on ApiException {
        await _clearAuth();
      }
    }
    ready = true;
    notifyListeners();
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
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> chooseWorkspace(Workspace next, {bool remember = true}) async {
    workspace = next;
    if (remember) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('organization_id', next.id);
    }
    await refresh();
    await _connect();
    notifyListeners();
  }

  Future<void> refresh() async {
    try {
      conversations = await api.conversations();
      threads = await api.inbox();
      error = null;
      errorStatus = null;
    } on ApiException catch (exception) {
      error = exception.message;
      errorStatus = exception.status;
    }
    notifyListeners();
    if (error == null) {
      unawaited(_ackReceipts());
    }
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
        unawaited(open ? api.markChatRead(message.conversationId) : api.markDelivered(message.conversationId));
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
  }

  void _applyMessage(ChatMessage message, {required bool notify}) {
    final current = messages[message.conversationId] ?? [];
    if (current.any((item) => item.id == message.id)) {
      return;
    }
    messages[message.conversationId] = [...current, message];
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
    _receiptAck.clear();
    error = null;
    errorStatus = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('token');
    await prefs.remove('organization_id');
  }

  String _normalize(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) {
      return 'https://maildesk.ng';
    }
    final withScheme = trimmed.contains('://') ? trimmed : 'https://$trimmed';
    return withScheme.endsWith('/') ? withScheme.substring(0, withScheme.length - 1) : withScheme;
  }

  @override
  void dispose() {
    _poll?.cancel();
    _typingTimer?.cancel();
    _live?.disconnect();
    super.dispose();
  }
}
