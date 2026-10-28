import 'package:maildesk/api.dart';

enum OutboxKind { compose, draft, reply, forward }

enum OutboxResult { sent, queued }

/// A mail action waiting for the network to come back.
class OutboxItem {
  const OutboxItem({
    required this.id,
    required this.kind,
    required this.organizationId,
    required this.createdAt,
    this.draftId,
    this.threadId,
    this.to = '',
    this.cc,
    this.bcc,
    this.subject = '',
    this.body = '',
    this.files = const [],
  });

  final String id;
  final OutboxKind kind;
  final int organizationId;
  final String createdAt;
  final int? draftId;
  final int? threadId;
  final String to;
  final String? cc;
  final String? bcc;
  final String subject;
  final String body;
  final List<String> files;

  Map<String, dynamic> toJson() => {
        'id': id,
        'kind': kind.name,
        'organization_id': organizationId,
        'created_at': createdAt,
        'draft_id': draftId,
        'thread_id': threadId,
        'to': to,
        'cc': cc,
        'bcc': bcc,
        'subject': subject,
        'body': body,
        'files': files,
      };

  factory OutboxItem.fromJson(Map<String, dynamic> json) {
    return OutboxItem(
      id: json['id'] as String? ?? '',
      kind: OutboxKind.values.firstWhere(
        (item) => item.name == json['kind'],
        orElse: () => OutboxKind.compose,
      ),
      organizationId: json['organization_id'] as int? ?? 0,
      createdAt: json['created_at'] as String? ?? '',
      draftId: json['draft_id'] as int?,
      threadId: json['thread_id'] as int?,
      to: json['to'] as String? ?? '',
      cc: json['cc'] as String?,
      bcc: json['bcc'] as String?,
      subject: json['subject'] as String? ?? '',
      body: json['body'] as String? ?? '',
      files: ((json['files'] as List?) ?? []).map((item) => item.toString()).toList(),
    );
  }

  String get label {
    final title = subject.trim().isEmpty ? '(no subject)' : subject.trim();
    return switch (kind) {
      OutboxKind.compose || OutboxKind.forward => 'Waiting to send · $title',
      OutboxKind.draft => 'Offline draft · $title',
      OutboxKind.reply => 'Waiting to send reply',
    };
  }
}

bool isOfflineError(Object error) {
  return error is ApiException && error.status == null;
}

String newOutboxId() => 'outbox-${DateTime.now().microsecondsSinceEpoch}';
