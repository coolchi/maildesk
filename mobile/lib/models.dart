import 'package:maildesk/chat_format.dart';

class Person {
  const Person({required this.id, required this.name, this.email, this.role});

  final int id;
  final String name;
  final String? email;
  final String? role;

  factory Person.fromJson(Map<String, dynamic> json) {
    return Person(
      id: json['id'] as int,
      name: json['name'] as String? ?? 'Member',
      email: json['email'] as String?,
      role: json['role'] as String?,
    );
  }
}

class SearchPerson {
  const SearchPerson({
    required this.id,
    required this.name,
    this.email,
    this.kind = 'member',
  });

  final int id;
  final String name;
  final String? email;
  final String kind;

  bool get isMember => kind == 'member';

  factory SearchPerson.fromJson(Map<String, dynamic> json) {
    return SearchPerson(
      id: json['id'] as int,
      name: json['name'] as String? ?? 'Person',
      email: json['email'] as String?,
      kind: json['kind'] as String? ?? 'member',
    );
  }
}

class SearchResults {
  const SearchResults({
    this.query = '',
    this.people = const [],
    this.chats = const [],
    this.mail = const [],
  });

  final String query;
  final List<SearchPerson> people;
  final List<ConversationSummary> chats;
  final List<MailThread> mail;

  bool get isEmpty => people.isEmpty && chats.isEmpty && mail.isEmpty;

  factory SearchResults.fromJson(Map<String, dynamic> json) {
    return SearchResults(
      query: json['query'] as String? ?? '',
      people: ((json['people'] as List?) ?? [])
          .whereType<Map>()
          .map((item) => SearchPerson.fromJson(Map<String, dynamic>.from(item)))
          .toList(),
      chats: ((json['chats'] as List?) ?? [])
          .whereType<Map>()
          .map((item) => ConversationSummary.fromJson(Map<String, dynamic>.from(item)))
          .toList(),
      mail: ((json['mail'] as List?) ?? [])
          .whereType<Map>()
          .map((item) => MailThread.fromJson(Map<String, dynamic>.from(item)))
          .toList(),
    );
  }
}

class Workspace {
  const Workspace({
    required this.id,
    required this.name,
    this.subdomain,
    this.status,
    this.role,
  });

  final int id;
  final String name;
  final String? subdomain;
  final String? status;
  final String? role;

  factory Workspace.fromJson(Map<String, dynamic> json) {
    return Workspace(
      id: json['id'] as int,
      name: json['name'] as String? ?? 'Workspace',
      subdomain: json['subdomain'] as String?,
      status: json['status'] as String?,
      role: json['role'] as String?,
    );
  }
}

class RealtimeConfig {
  const RealtimeConfig({
    required this.key,
    required this.host,
    required this.port,
    required this.scheme,
    required this.authEndpoint,
  });

  final String? key;
  final String? host;
  final int port;
  final String scheme;
  final String authEndpoint;

  bool get ready => key != null && key!.isNotEmpty && host != null && host!.isNotEmpty;

  factory RealtimeConfig.fromJson(Map<String, dynamic> json) {
    return RealtimeConfig(
      key: json['key'] as String?,
      host: json['host'] as String?,
      port: json['port'] as int? ?? 8080,
      scheme: json['scheme'] as String? ?? 'https',
      authEndpoint: json['auth_endpoint'] as String? ?? '',
    );
  }
}

class Participant {
  const Participant({
    required this.id,
    required this.name,
    required this.role,
    this.lastReadAt,
    this.lastDeliveredAt,
    this.lastSeenAt,
    this.online = false,
  });

  final int id;
  final String name;
  final String role;
  final String? lastReadAt;
  final String? lastDeliveredAt;
  final String? lastSeenAt;
  final bool online;

  Participant copyWith({String? lastReadAt, String? lastDeliveredAt, String? lastSeenAt, bool? online}) {
    return Participant(
      id: id,
      name: name,
      role: role,
      lastReadAt: lastReadAt ?? this.lastReadAt,
      lastDeliveredAt: lastDeliveredAt ?? this.lastDeliveredAt,
      lastSeenAt: lastSeenAt ?? this.lastSeenAt,
      online: online ?? this.online,
    );
  }

  factory Participant.fromJson(Map<String, dynamic> json) {
    return Participant(
      id: json['id'] as int,
      name: json['name'] as String? ?? 'Former member',
      role: json['role'] as String? ?? 'member',
      lastReadAt: json['last_read_at'] as String?,
      lastDeliveredAt: json['last_delivered_at'] as String?,
      lastSeenAt: json['last_seen_at'] as String?,
      online: json['online'] as bool? ?? false,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'role': role,
        'last_read_at': lastReadAt,
        'last_delivered_at': lastDeliveredAt,
        'last_seen_at': lastSeenAt,
        'online': online,
      };
}

class ConversationSummary {
  const ConversationSummary({
    required this.id,
    required this.type,
    required this.name,
    required this.unreadCount,
    required this.participants,
    this.preview,
    this.lastMessageAt,
    this.pinned = false,
    this.muted = false,
    this.archived = false,
  });

  final int id;
  final String type;
  final String name;
  final String? preview;
  final String? lastMessageAt;
  final int unreadCount;
  final bool pinned;
  final bool muted;
  final bool archived;
  final List<Participant> participants;

  bool get isGroup => type == 'group';

  ConversationSummary copyWith({
    String? preview,
    String? lastMessageAt,
    int? unreadCount,
    bool? pinned,
    bool? muted,
    bool? archived,
    List<Participant>? participants,
  }) {
    return ConversationSummary(
      id: id,
      type: type,
      name: name,
      preview: preview ?? this.preview,
      lastMessageAt: lastMessageAt ?? this.lastMessageAt,
      unreadCount: unreadCount ?? this.unreadCount,
      pinned: pinned ?? this.pinned,
      muted: muted ?? this.muted,
      archived: archived ?? this.archived,
      participants: participants ?? this.participants,
    );
  }

  factory ConversationSummary.fromJson(Map<String, dynamic> json) {
    return ConversationSummary(
      id: json['id'] as int,
      type: json['type'] as String? ?? 'direct',
      name: json['name'] as String? ?? 'Chat',
      preview: json['preview'] as String?,
      lastMessageAt: json['last_message_at'] as String?,
      unreadCount: json['unread_count'] as int? ?? 0,
      pinned: json['pinned'] as bool? ?? false,
      muted: json['muted'] as bool? ?? false,
      archived: json['archived'] as bool? ?? false,
      participants: ((json['participants'] as List?) ?? [])
          .map((item) => Participant.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'type': type,
        'name': name,
        'preview': preview,
        'last_message_at': lastMessageAt,
        'unread_count': unreadCount,
        'pinned': pinned,
        'muted': muted,
        'archived': archived,
        'participants': participants.map((item) => item.toJson()).toList(),
      };
}

class ChatFile {
  const ChatFile({
    required this.id,
    required this.filename,
    required this.url,
    required this.isImage,
    this.contentType,
    this.sizeLabel,
    this.durationMs,
  });

  final int id;
  final String filename;
  final String url;
  final bool isImage;
  final String? contentType;
  final String? sizeLabel;
  final int? durationMs;

  factory ChatFile.fromJson(Map<String, dynamic> json) {
    return ChatFile(
      id: json['id'] as int,
      filename: json['filename'] as String? ?? 'file',
      url: json['url'] as String? ?? '',
      isImage: json['is_image'] as bool? ?? false,
      contentType: json['content_type'] as String?,
      sizeLabel: json['size_label'] as String?,
      durationMs: json['duration_ms'] as int?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'filename': filename,
        'url': url,
        'is_image': isImage,
        'content_type': contentType,
        'size_label': sizeLabel,
        'duration_ms': durationMs,
      };
}

class ChatMessage {
  const ChatMessage({
    required this.id,
    required this.conversationId,
    required this.body,
    required this.userName,
    this.createdAt,
    this.userId,
    this.kind = 'text',
    this.attachments = const [],
    this.pending = false,
  });

  final int id;
  final int conversationId;
  final String body;
  final String? createdAt;
  final int? userId;
  final String userName;
  final String kind;
  final List<ChatFile> attachments;
  final bool pending;

  String get listPreview {
    final text = stripChatFormat(body).trim();
    if (text.isNotEmpty) {
      return text;
    }
    return switch (kind) {
      'image' => 'Photo',
      'voice' => 'Voice note',
      'file' => attachments.isEmpty ? 'Attachment' : attachments.first.filename,
      _ => '',
    };
  }

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    final user = json['user'] as Map<String, dynamic>? ?? {};
    return ChatMessage(
      id: json['id'] as int,
      conversationId: json['conversation_id'] as int,
      body: json['body'] as String? ?? '',
      createdAt: json['created_at'] as String?,
      userId: user['id'] as int?,
      userName: user['name'] as String? ?? 'Former member',
      kind: json['kind'] as String? ?? 'text',
      attachments: ((json['attachments'] as List?) ?? [])
          .map((item) => ChatFile.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'conversation_id': conversationId,
        'body': body,
        'created_at': createdAt,
        'kind': kind,
        'user': {'id': userId, 'name': userName},
        'attachments': attachments.map((item) => item.toJson()).toList(),
      };
}

class MailThread {
  const MailThread({
    required this.id,
    required this.subject,
    required this.snippet,
    required this.unread,
    required this.updated,
    this.fromName,
    this.fromEmail,
  });

  final int id;
  final String subject;
  final String snippet;
  final bool unread;
  final String updated;
  final String? fromName;
  final String? fromEmail;

  String get sender => (fromName != null && fromName!.trim().isNotEmpty) ? fromName!.trim() : (fromEmail ?? 'Unknown');

  factory MailThread.fromJson(Map<String, dynamic> json) {
    return MailThread(
      id: json['id'] as int,
      subject: json['subject'] as String? ?? '(no subject)',
      snippet: json['snippet'] as String? ?? '',
      unread: json['unread'] as bool? ?? false,
      updated: json['updated'] as String? ?? '',
      fromName: json['from_name'] as String?,
      fromEmail: json['from_email'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'subject': subject,
        'snippet': snippet,
        'unread': unread,
        'updated': updated,
        'from_name': fromName,
        'from_email': fromEmail,
      };
}

class MailFile {
  const MailFile({
    required this.filename,
    required this.url,
    this.previewUrl,
    this.sizeLabel,
    this.isImage = false,
    this.isVideo = false,
    this.previewKind,
    this.contentType,
  });

  final String filename;
  final String url;
  final String? previewUrl;
  final String? sizeLabel;
  final bool isImage;
  final bool isVideo;
  final String? previewKind;
  final String? contentType;

  bool get isPdf => previewKind == 'pdf' || filename.toLowerCase().endsWith('.pdf');

  bool get canPreview => previewUrl != null && (isImage || isVideo || isPdf);

  factory MailFile.fromJson(Map<String, dynamic> json) {
    return MailFile(
      filename: json['filename'] as String? ?? 'file',
      url: json['url'] as String? ?? '',
      previewUrl: json['preview_url'] as String?,
      sizeLabel: json['size_label'] as String?,
      isImage: json['is_image'] as bool? ?? false,
      isVideo: json['is_video'] as bool? ?? false,
      previewKind: json['preview_kind'] as String?,
      contentType: json['content_type'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'filename': filename,
        'url': url,
        'preview_url': previewUrl,
        'size_label': sizeLabel,
        'is_image': isImage,
        'is_video': isVideo,
        'preview_kind': previewKind,
        'content_type': contentType,
      };
}

class AiCapabilities {
  const AiCapabilities({
    this.composeAssist = false,
    this.replyDraft = false,
    this.threadSummary = false,
  });

  final bool composeAssist;
  final bool replyDraft;
  final bool threadSummary;

  factory AiCapabilities.fromJson(Map<String, dynamic> json) {
    return AiCapabilities(
      composeAssist: json['compose_assist'] as bool? ?? false,
      replyDraft: json['reply_draft'] as bool? ?? false,
      threadSummary: json['thread_summary'] as bool? ?? false,
    );
  }
}

class ComposeAssist {
  const ComposeAssist({this.text, this.subject, this.subjects = const []});

  final String? text;
  final String? subject;
  final List<String> subjects;

  factory ComposeAssist.fromJson(Map<String, dynamic> json) {
    return ComposeAssist(
      text: json['text'] as String?,
      subject: json['subject'] as String?,
      subjects: ((json['subjects'] as List?) ?? []).map((item) => item.toString()).toList(),
    );
  }
}

class ThreadSummary {
  const ThreadSummary({required this.summary, this.actionItems = const []});

  final String summary;
  final List<String> actionItems;

  factory ThreadSummary.fromJson(Map<String, dynamic> json) {
    return ThreadSummary(
      summary: json['summary'] as String? ?? '',
      actionItems: ((json['action_items'] as List?) ?? []).map((item) => item.toString()).toList(),
    );
  }
}

class MailDraft {
  const MailDraft({
    required this.id,
    required this.subject,
    this.to,
    this.cc,
    this.bcc,
    this.body = '',
    this.updated,
  });

  final int id;
  final String subject;
  final String? to;
  final String? cc;
  final String? bcc;
  final String body;
  final String? updated;

  factory MailDraft.fromJson(Map<String, dynamic> json) {
    return MailDraft(
      id: json['id'] as int,
      subject: json['subject'] as String? ?? '',
      to: json['to'] as String?,
      cc: json['cc'] as String?,
      bcc: json['bcc'] as String?,
      body: json['body'] as String? ?? '',
      updated: json['updated'] as String?,
    );
  }
}

class MailMessage {
  const MailMessage({
    required this.from,
    required this.body,
    required this.sent,
    this.html,
    this.fromName,
    this.attachments = const [],
  });

  final String from;
  final String? fromName;
  final String body;
  final String? html;
  final String sent;
  final List<MailFile> attachments;

  factory MailMessage.fromJson(Map<String, dynamic> json) {
    final text = (json['text'] as String?)?.trim();
    final html = (json['html'] as String?)?.trim();
    return MailMessage(
      from: json['from'] as String? ?? '',
      fromName: json['from_name'] as String?,
      html: (html != null && html.isNotEmpty) ? html : null,
      body: (text != null && text.isNotEmpty) ? text : _plain(html ?? ''),
      sent: json['sent'] as String? ?? '',
      attachments: ((json['attachments'] as List?) ?? [])
          .map((item) => MailFile.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'from': from,
        'from_name': fromName,
        'html': html,
        'text': body,
        'sent': sent,
        'attachments': attachments.map((item) => item.toJson()).toList(),
      };

  static String _plain(String html) {
    return html
        .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'</p>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'<[^>]+>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .trim();
  }
}

class MailDetail {
  const MailDetail({required this.subject, required this.messages});

  final String subject;
  final List<MailMessage> messages;

  factory MailDetail.fromJson(Map<String, dynamic> json) {
    return MailDetail(
      subject: json['subject'] as String? ?? '(no subject)',
      messages: ((json['messages'] as List?) ?? [])
          .map((item) => MailMessage.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'subject': subject,
        'messages': messages.map((item) => item.toJson()).toList(),
      };
}

String initials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((part) => part.isNotEmpty).toList();
  if (parts.isEmpty) {
    return '?';
  }
  if (parts.length == 1) {
    return parts.first.substring(0, 1).toUpperCase();
  }
  return (parts.first.substring(0, 1) + parts.last.substring(0, 1)).toUpperCase();
}

String shortTime(String? iso) {
  final date = DateTime.tryParse(iso ?? '')?.toLocal();
  if (date == null) {
    return '';
  }
  final now = DateTime.now();
  final time = '${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';
  final sameDay = date.year == now.year && date.month == now.month && date.day == now.day;
  if (sameDay) {
    return time;
  }
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return '${months[date.month - 1]} ${date.day}';
}

/// Peers in a chat, excluding the signed-in person.
List<Participant> otherParticipants(ConversationSummary conversation, int? userId) {
  return [
    for (final participant in conversation.participants)
      if (participant.id != userId) participant,
  ];
}

bool conversationOnline(ConversationSummary conversation, int? userId) {
  return otherParticipants(conversation, userId).any((participant) => participant.online);
}

/// "Online" or "Active 5m ago" for a direct chat; group chats keep a people count.
String? presenceLabel(ConversationSummary conversation, int? userId) {
  final others = otherParticipants(conversation, userId);
  if (conversation.isGroup) {
    final online = others.where((participant) => participant.online).length;
    final people = '${conversation.participants.length} people';
    if (online == 0) {
      return people;
    }
    return '$people · $online online';
  }
  if (others.isEmpty) {
    return 'Offline';
  }
  final peer = others.first;
  if (peer.online) {
    return 'Online';
  }
  return activeAgo(peer.lastSeenAt) ??
      activeAgo(peer.lastReadAt) ??
      activeAgo(peer.lastDeliveredAt) ??
      'Offline';
}

String? activeAgo(String? iso) {
  final date = DateTime.tryParse(iso ?? '')?.toLocal();
  if (date == null) {
    return null;
  }
  final seconds = DateTime.now().difference(date).inSeconds;
  if (seconds < 60) {
    return 'Active just now';
  }
  if (seconds < 3600) {
    final minutes = (seconds / 60).floor();
    return 'Active ${minutes}m ago';
  }
  if (seconds < 86400) {
    final hours = (seconds / 3600).floor();
    return 'Active ${hours}h ago';
  }
  final days = (seconds / 86400).floor();
  if (days < 7) {
    return 'Active ${days}d ago';
  }
  return 'Active ${shortTime(iso)}';
}
