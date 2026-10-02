import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/empty.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/screens/compose_page.dart';
import 'package:maildesk/screens/conversation_page.dart';
import 'package:maildesk/screens/mail_page.dart';
import 'package:maildesk/theme.dart';

class SearchPage extends StatefulWidget {
  const SearchPage({super.key});

  @override
  State<SearchPage> createState() => _SearchPageState();
}

class _SearchPageState extends State<SearchPage> {
  final field = TextEditingController();
  final focus = FocusNode();
  SearchResults results = const SearchResults();
  var loading = true;
  String? error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      focus.requestFocus();
      _load('');
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    field.dispose();
    focus.dispose();
    super.dispose();
  }

  Future<void> _load(String query) async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final next = await Desk.read(context).api.search(query);
      if (!mounted || field.text.trim() != query.trim()) {
        return;
      }
      setState(() {
        results = next;
        loading = false;
      });
    } on ApiException catch (exception) {
      if (!mounted) {
        return;
      }
      setState(() {
        error = exception.message;
        loading = false;
        results = _localResults(query);
      });
    }
  }

  SearchResults _localResults(String query) {
    final session = Desk.read(context);
    final needle = query.trim().toLowerCase();
    final people = <SearchPerson>[];
    final seen = <int>{};
    for (final conversation in session.conversations) {
      for (final participant in conversation.participants) {
        if (participant.id == session.user?.id || seen.contains(participant.id)) {
          continue;
        }
        if (needle.isNotEmpty && !participant.name.toLowerCase().contains(needle)) {
          continue;
        }
        seen.add(participant.id);
        people.add(SearchPerson(id: participant.id, name: participant.name, kind: 'member'));
      }
    }
    final chats = session.conversations.where((conversation) {
      if (needle.isEmpty) {
        return true;
      }
      final haystack = '${conversation.name} ${conversation.preview ?? ''}'.toLowerCase();
      return haystack.contains(needle);
    }).take(8).toList();
    final mail = session.threads.where((thread) {
      if (needle.isEmpty) {
        return true;
      }
      final haystack = '${thread.subject} ${thread.snippet} ${thread.sender}'.toLowerCase();
      return haystack.contains(needle);
    }).take(8).toList();

    return SearchResults(
      query: query,
      people: people.take(8).toList(),
      chats: chats,
      mail: mail,
    );
  }

  void _onQuery(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 220), () => _load(value));
  }

  Future<void> _openPerson(SearchPerson person) async {
    HapticFeedback.selectionClick();
    if (!person.isMember) {
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ComposePage(
            draft: MailDraft(id: 0, subject: '', to: person.email, body: ''),
          ),
        ),
      );
      return;
    }
    try {
      final conversation = await Desk.of(context).api.openConversation(type: 'direct', userIds: [person.id]);
      if (!mounted) {
        return;
      }
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConversationPage(conversation: conversation)));
    } on ApiException catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(exception.message)));
      }
    }
  }

  Future<void> _openChat(ConversationSummary conversation) async {
    HapticFeedback.selectionClick();
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConversationPage(conversation: conversation)));
  }

  Future<void> _openMail(MailThread thread) async {
    HapticFeedback.selectionClick();
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => MailPage(thread: thread)));
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final recent = field.text.trim().isEmpty;

    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(
        titleSpacing: 0,
        title: TextField(
          controller: field,
          focusNode: focus,
          textInputAction: TextInputAction.search,
          onChanged: _onQuery,
          onSubmitted: _load,
          style: TextStyle(fontSize: 16, color: colors.text),
          cursorColor: colors.accent,
          decoration: InputDecoration(
            hintText: 'Search mail and chat',
            hintStyle: TextStyle(color: colors.muted),
            border: InputBorder.none,
            isDense: true,
          ),
        ),
        actions: [
          if (field.text.isNotEmpty)
            IconButton(
              tooltip: 'Clear',
              onPressed: () {
                field.clear();
                _load('');
              },
              icon: const Icon(LucideIcons.x, size: 18),
            ),
        ],
      ),
      body: loading && results.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : results.isEmpty
              ? EmptyPane(
                  icon: LucideIcons.search,
                  title: recent ? 'Nothing recent yet' : 'No matches',
                  message: recent
                      ? 'People, chats, and mail you use will show up here.'
                      : 'Try another name, subject, or phrase.',
                )
              : ListView(
                  padding: const EdgeInsets.only(bottom: 24),
                  children: [
                    if (error != null)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                        child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 13)),
                      ),
                    if (results.people.isNotEmpty)
                      _Section(
                        title: recent ? 'Recent people' : 'People',
                        children: [
                          for (final person in results.people)
                            _SearchTile(
                              icon: person.isMember ? LucideIcons.user : LucideIcons.contact,
                              title: person.name,
                              subtitle: person.email ?? (person.isMember ? 'Teammate' : 'Contact'),
                              onTap: () => _openPerson(person),
                            ),
                        ],
                      ),
                    if (results.chats.isNotEmpty)
                      _Section(
                        title: recent ? 'Recent chats' : 'Chats',
                        children: [
                          for (final chat in results.chats)
                            _SearchTile(
                              icon: chat.type == 'group' ? LucideIcons.users : LucideIcons.messageCircle,
                              title: chat.name,
                              subtitle: chat.preview ?? 'Chat',
                              onTap: () => _openChat(chat),
                            ),
                        ],
                      ),
                    if (results.mail.isNotEmpty)
                      _Section(
                        title: recent ? 'Recent mail' : 'Mail',
                        children: [
                          for (final thread in results.mail)
                            _SearchTile(
                              icon: LucideIcons.mail,
                              title: thread.subject,
                              subtitle: '${thread.sender} · ${thread.snippet}',
                              unread: thread.unread,
                              onTap: () => _openMail(thread),
                            ),
                        ],
                      ),
                  ],
                ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 18, 16, 8),
          child: Text(title, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: colors.muted)),
        ),
        ...children,
      ],
    );
  }
}

class _SearchTile extends StatelessWidget {
  const _SearchTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.unread = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool unread;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return ListTile(
      onTap: onTap,
      leading: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: colors.accent.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Icon(icon, size: 18, color: colors.accent),
      ),
      title: Text(
        title.isEmpty ? '(no subject)' : title,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(fontWeight: unread ? FontWeight.w600 : FontWeight.w500, color: colors.text),
      ),
      subtitle: Text(
        subtitle,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(color: colors.muted, fontSize: 13),
      ),
      trailing: Icon(LucideIcons.chevronRight, size: 16, color: colors.muted),
    );
  }
}
