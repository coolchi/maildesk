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
  });

  final int id;
  final String name;
  final String role;
  final String? lastReadAt;
  final String? lastDeliveredAt;

  Participant copyWith({String? lastReadAt, String? lastDeliveredAt}) {
    return Participant(
      id: id,
      name: name,
      role: role,
      lastReadAt: lastReadAt ?? this.lastReadAt,
      lastDeliveredAt: lastDeliveredAt ?? this.lastDeliveredAt,
    );
  }

  factory Participant.fromJson(Map<String, dynamic> json) {
    return Participant(
      id: json['id'] as int,
      name: json['name'] as String? ?? 'Former member',
      role: json['role'] as String? ?? 'member',
      lastReadAt: json['last_read_at'] as String?,
      lastDeliveredAt: json['last_delivered_at'] as String?,
    );
  }
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
  });

  final int id;
  final String type;
  final String name;
  final String? preview;
  final String? lastMessageAt;
  final int unreadCount;
  final bool pinned;
  final List<Participant> participants;

  bool get isGroup => type == 'group';

  ConversationSummary copyWith({
    String? preview,
    String? lastMessageAt,
    int? unreadCount,
    bool? pinned,
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
      participants: ((json['participants'] as List?) ?? [])
          .map((item) => Participant.fromJson(item as Map<String, dynamic>))
          .toList(),
    );
  }
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
