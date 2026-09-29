import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:flutter/services.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/screens/compose_page.dart';
import 'package:maildesk/theme.dart';

class PeoplePage extends StatefulWidget {
  const PeoplePage({super.key});

  @override
  State<PeoplePage> createState() => _PeoplePageState();
}

class _PeoplePageState extends State<PeoplePage> {
  var group = false;
  var loading = true;
  var saving = false;
  String? error;
  List<Person> people = [];
  final selected = <int>{};
  final name = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final session = Desk.read(context);
    try {
      final members = await session.api.members();
      final me = session.user?.id;
      if (!mounted) {
        return;
      }
      setState(() {
        people = members.where((person) => person.id != me).toList();
        loading = false;
      });
    } on ApiException catch (exception) {
      if (!mounted) {
        return;
      }
      setState(() {
        error = exception.message;
        loading = false;
      });
    }
  }

  Future<void> _openDirect(Person person) async {
    if (saving) {
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final conversation = await Desk.of(context).api.openConversation(type: 'direct', userIds: [person.id]);
      if (mounted) {
        Navigator.of(context).pop(conversation);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() {
          saving = false;
          error = exception.message;
        });
      }
    }
  }

  Future<void> _create() async {
    final session = Desk.of(context);
    if (selected.isEmpty) {
      setState(() => error = 'Choose at least one person.');
      return;
    }
    if (group && name.text.trim().isEmpty) {
      setState(() => error = 'Give the group a name.');
      return;
    }
    if (!group && selected.length != 1) {
      setState(() => error = 'A direct chat is with one person.');
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final conversation = await session.api.openConversation(
        type: group ? 'group' : 'direct',
        userIds: selected.toList(),
        name: group ? name.text.trim() : null,
      );
      if (mounted) {
        Navigator.of(context).pop(conversation);
      }
    } on ApiException catch (exception) {
      setState(() {
        saving = false;
        error = exception.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(group ? 'New group' : 'New chat'),
        actions: [
          IconButton(
            tooltip: 'Add contact',
            onPressed: () => showAddContact(context),
            icon: const Icon(LucideIcons.userPlus),
          ),
          if (group)
            TextButton(
              onPressed: saving ? null : _create,
              child: saving
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Create'),
            ),
        ],
      ),
      body: loading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                  child: SegmentedButton<bool>(
                    segments: const [
                      ButtonSegment(value: false, label: Text('Direct'), icon: Icon(LucideIcons.user)),
                      ButtonSegment(value: true, label: Text('Group'), icon: Icon(LucideIcons.users)),
                    ],
                    selected: {group},
                    onSelectionChanged: (value) => setState(() {
                      group = value.first;
                      if (!group && selected.length > 1) {
                        final keep = selected.first;
                        selected
                          ..clear()
                          ..add(keep);
                      }
                    }),
                  ),
                ),
                if (group)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: TextField(
                      controller: name,
                      textCapitalization: TextCapitalization.words,
                      decoration: const InputDecoration(labelText: 'Group name'),
                    ),
                  ),
                if (error != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                    child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ),
                Expanded(
                  child: ListView.builder(
                    itemCount: people.length,
                    itemBuilder: (context, index) {
                      final person = people[index];
                      final checked = selected.contains(person.id);
                      return ListTile(
                        leading: CircleAvatar(
                          backgroundColor: colors.accent.withValues(alpha: 0.12),
                          foregroundColor: colors.accent,
                          child: Text(initials(person.name)),
                        ),
                        title: Text(person.name),
                        subtitle: Text(person.email ?? ''),
                        trailing: group
                            ? Icon(
                                checked ? LucideIcons.checkCircle2 : LucideIcons.circle,
                                color: checked ? colors.accent : colors.muted,
                              )
                            : Icon(LucideIcons.chevronRight, size: 18, color: colors.muted),
                        onTap: () {
                          HapticFeedback.selectionClick();
                          if (!group) {
                            _openDirect(person);
                            return;
                          }
                          setState(() {
                            checked ? selected.remove(person.id) : selected.add(person.id);
                          });
                        },
                      );
                    },
                  ),
                ),
              ],
            ),
    );
  }
}
