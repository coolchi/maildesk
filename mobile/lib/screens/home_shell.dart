import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/main.dart';
import 'package:maildesk/screens/chat_list_page.dart';
import 'package:maildesk/screens/compose_page.dart';
import 'package:maildesk/screens/inbox_page.dart';
import 'package:maildesk/screens/notifications_page.dart';
import 'package:maildesk/session.dart';
import 'package:maildesk/theme.dart';
import 'package:url_launcher/url_launcher.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  var index = 1;
  var folder = 'inbox';
  var reload = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        Desk.read(context).setInboxVisible(true);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    final unreadChats = session.conversations.fold<int>(0, (sum, conversation) => sum + conversation.unreadCount);
    final unreadMail = session.threads.where((thread) => thread.unread).length;

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(index == 0 ? 'Chats' : _folderTitle()),
            Text(
              session.workspace?.name ?? 'MailDesk',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w400, color: colors.muted),
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Notifications',
            onPressed: () async {
              await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsPage()));
              if (mounted) {
                setState(() => reload++);
              }
            },
            icon: _Badge(icon: LucideIcons.bell, count: noticeCount(session)),
          ),
          _SectionButton(
            tooltip: 'Chats',
            selected: index == 0,
            icon: index == 0 ? LucideIcons.messagesSquare : LucideIcons.messageCircle,
            count: unreadChats,
            onPressed: () => _open(0),
          ),
          _SectionButton(
            tooltip: 'Inbox',
            selected: index == 1,
            icon: index == 1 ? LucideIcons.mailbox : LucideIcons.inbox,
            count: unreadMail,
            onPressed: () => _open(1),
          ),
          if (index == 0)
            IconButton(
              tooltip: 'New chat',
              onPressed: () => ChatListPage.openNew(context),
              icon: const Icon(LucideIcons.pencil, size: 20),
            ),
          IconButton(
            tooltip: 'More',
            onPressed: () => _accountMenu(session),
            icon: const Icon(LucideIcons.moreHorizontal, size: 20),
          ),
        ],
      ),
      body: Column(
        children: [
          if (session.error != null)
            session.errorStatus == 402
                ? _TrialNotice(
                    message: session.error!,
                    onPlans: () {
                      final uri = Uri.parse('${session.baseUrl}/settings/billing');
                      launchUrl(uri, mode: LaunchMode.externalApplication);
                    },
                  )
                : Material(
                    color: Theme.of(context).colorScheme.errorContainer,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      child: Text(session.error!),
                    ),
                  ),
          Expanded(
            child: IndexedStack(
              index: index,
              children: [
                const ChatListPage(),
                InboxPage(
                  folder: folder,
                  reload: reload,
                  onSent: () => setState(() {
                    folder = 'sent';
                    reload++;
                  }),
                ),
              ],
            ),
          ),
          InboxFooter(folder: index == 1 ? folder : '', onSelect: _onFolder),
        ],
      ),
    );
  }

  Future<void> _onFolder(String next) async {
    if (next == 'compose') {
      final sent = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const ComposePage()));
      if (sent == true && mounted) {
        Desk.read(context).setInboxVisible(true);
        setState(() {
          index = 1;
          folder = 'sent';
          reload++;
        });
      }
      return;
    }
    if (next == 'more') {
      await _moreMenu();
      return;
    }
    Desk.read(context).setInboxVisible(true);
    setState(() {
      index = 1;
      folder = next;
    });
  }

  Future<void> _moreMenu() async {
    final colors = deskColors(context);
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      backgroundColor: colors.panel,
      builder: (context) => const _MoreSheet(),
    );
    if (!mounted || choice == null) {
      return;
    }
    if (choice == 'contact') {
      await showAddContact(context);
      return;
    }
    Desk.read(context).setInboxVisible(true);
    setState(() {
      index = 1;
      folder = choice;
    });
  }

  Future<void> _accountMenu(Session session) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _AccountSheet(session: session),
    );
  }

  String _folderTitle() {
    return switch (folder) {
      'sent' => 'Sent',
      'drafts' => 'Drafts',
      'archive' => 'Archive',
      'spam' => 'Spam',
      'trash' => 'Trash',
      _ => 'Inbox',
    };
  }

  void _open(int value) {
    Desk.read(context).setInboxVisible(value == 1);
    setState(() => index = value);
  }
}

class _SectionButton extends StatelessWidget {
  const _SectionButton({
    required this.tooltip,
    required this.selected,
    required this.icon,
    required this.count,
    required this.onPressed,
  });

  final String tooltip;
  final bool selected;
  final IconData icon;
  final int count;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: IconButton(
        tooltip: tooltip,
        onPressed: onPressed,
        style: IconButton.styleFrom(
          backgroundColor: selected ? colors.accent.withValues(alpha: 0.14) : Colors.transparent,
          foregroundColor: selected ? colors.accent : colors.muted,
          minimumSize: const Size(40, 40),
          fixedSize: const Size(40, 40),
          padding: EdgeInsets.zero,
          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
          iconSize: 20,
        ),
        icon: _Badge(icon: icon, count: count),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.icon, required this.count});

  final IconData icon;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Badge(
      isLabelVisible: count > 0,
      label: Text(count > 99 ? '99+' : '$count'),
      child: Icon(icon),
    );
  }
}

class _TrialNotice extends StatelessWidget {
  const _TrialNotice({required this.message, required this.onPlans});

  final String message;
  final VoidCallback onPlans;

  @override
  Widget build(BuildContext context) {
    const amber = Color(0xFFF59E0B);
    const ink = Color(0xFF1C1917);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: amber.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: amber.withValues(alpha: 0.35)),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Row(
            children: [
              Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: amber.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(LucideIcons.clock, size: 15, color: amber),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Trial ended', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, height: 1.1, color: Color(0xFFFDE68A))),
                    const SizedBox(height: 1),
                    Text(message, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Color(0xFFF5E6C8), fontSize: 12, height: 1.2)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                onPressed: onPlans,
                style: FilledButton.styleFrom(
                  backgroundColor: amber,
                  foregroundColor: ink,
                  visualDensity: VisualDensity.compact,
                  minimumSize: const Size(0, 28),
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  shape: const StadiumBorder(),
                  textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                ),
                child: const Text('View plans'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MoreSheet extends StatelessWidget {
  const _MoreSheet();

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('More', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600, color: colors.text)),
            const SizedBox(height: 4),
            Text('Folders and people', style: TextStyle(color: colors.muted, fontSize: 13)),
            const SizedBox(height: 16),
            _ActionGroup(
              children: [
                _SheetAction(
                  icon: LucideIcons.archive,
                  title: 'Archive',
                  subtitle: 'Mail you filed away',
                  onTap: () => Navigator.pop(context, 'archive'),
                ),
                _SheetAction(
                  icon: LucideIcons.shieldAlert,
                  title: 'Spam',
                  subtitle: 'Messages marked as spam',
                  tone: const Color(0xFFF59E0B),
                  onTap: () => Navigator.pop(context, 'spam'),
                ),
                _SheetAction(
                  icon: LucideIcons.trash2,
                  title: 'Trash',
                  subtitle: 'Deleted conversations',
                  tone: const Color(0xFFEF4444),
                  onTap: () => Navigator.pop(context, 'trash'),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _ActionGroup(
              children: [
                _SheetAction(
                  icon: LucideIcons.userPlus,
                  title: 'Add contact',
                  subtitle: 'Save someone you write to',
                  tone: colors.accent,
                  onTap: () => Navigator.pop(context, 'contact'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _ActionGroup extends StatelessWidget {
  const _ActionGroup({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Material(
      color: colors.bg,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: colors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (var index = 0; index < children.length; index++) ...[
            if (index > 0) Divider(height: 1, color: colors.border),
            children[index],
          ],
        ],
      ),
    );
  }
}

class _SheetAction extends StatelessWidget {
  const _SheetAction({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.tone,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final color = tone ?? colors.secondary;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, size: 18, color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: TextStyle(fontWeight: FontWeight.w500, fontSize: 15, color: colors.text)),
                  const SizedBox(height: 2),
                  Text(subtitle, style: TextStyle(color: colors.muted, fontSize: 12)),
                ],
              ),
            ),
            Icon(LucideIcons.chevronRight, size: 16, color: colors.muted),
          ],
        ),
      ),
    );
  }
}

class _AppearanceSwitch extends StatelessWidget {
  const _AppearanceSwitch();

  @override
  Widget build(BuildContext context) {
    final session = Desk.of(context);
    final colors = deskColors(context);
    final selected = session.theme ?? (Theme.of(context).brightness == Brightness.dark ? 'dark' : 'light');

    return DecoratedBox(
      decoration: BoxDecoration(
        color: colors.bg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: colors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(4),
        child: Row(
          children: [
            Expanded(child: _mode(context, session, colors, 'light', 'Light', LucideIcons.sun, selected == 'light')),
            Expanded(child: _mode(context, session, colors, 'dark', 'Dark', LucideIcons.moon, selected == 'dark')),
          ],
        ),
      ),
    );
  }

  Widget _mode(
    BuildContext context,
    Session session,
    MailDeskColors colors,
    String value,
    String label,
    IconData icon,
    bool selected,
  ) {
    final tone = selected ? colors.accent : colors.secondary;
    return Material(
      color: selected ? colors.accent.withValues(alpha: 0.12) : Colors.transparent,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () {
          HapticFeedback.selectionClick();
          session.setTheme(value);
        },
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 16, color: tone),
              const SizedBox(width: 8),
              Text(label, style: TextStyle(fontWeight: FontWeight.w500, color: selected ? colors.accent : colors.text)),
            ],
          ),
        ),
      ),
    );
  }
}

class _AccountSheet extends StatelessWidget {
  const _AccountSheet({required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final current = session.workspace;
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.8),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(session.user?.name ?? 'MailDesk', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              if (session.user?.email != null)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(session.user!.email!, style: TextStyle(color: colors.muted, fontSize: 13)),
                ),
              const SizedBox(height: 16),
              Text('Appearance', style: TextStyle(fontSize: 13, color: colors.muted, fontWeight: FontWeight.w500)),
              const SizedBox(height: 8),
              const _AppearanceSwitch(),
              if (session.workspaces.length > 1) ...[
                const SizedBox(height: 16),
                Text('Workspaces', style: TextStyle(fontSize: 13, color: colors.muted, fontWeight: FontWeight.w500)),
                const SizedBox(height: 8),
                Flexible(
                  child: ListView.builder(
                    shrinkWrap: true,
                    itemCount: session.workspaces.length,
                    itemBuilder: (context, index) {
                      final workspace = session.workspaces[index];
                      return _MenuRow(
                        icon: LucideIcons.building2,
                        title: workspace.name,
                        subtitle: workspace.role ?? workspace.subdomain,
                        selected: workspace.id == current?.id,
                        onTap: () {
                          Navigator.pop(context);
                          if (workspace.id != current?.id) {
                            session.chooseWorkspace(workspace);
                          }
                        },
                      );
                    },
                  ),
                ),
              ],
              const SizedBox(height: 8),
              _MenuRow(
                icon: LucideIcons.logOut,
                title: 'Sign out',
                danger: true,
                onTap: () {
                  Navigator.pop(context);
                  session.signOut();
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MenuRow extends StatelessWidget {
  const _MenuRow({
    required this.icon,
    required this.title,
    required this.onTap,
    this.subtitle,
    this.selected = false,
    this.danger = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback onTap;
  final bool selected;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final tone = danger ? const Color(0xFFEF4444) : (selected ? colors.accent : colors.secondary);
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Material(
        color: selected ? colors.accent.withValues(alpha: 0.08) : colors.bg,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: selected ? colors.accent.withValues(alpha: 0.35) : colors.border),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: tone.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(icon, size: 16, color: tone),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: TextStyle(fontWeight: FontWeight.w500, color: danger ? tone : colors.text)),
                      if (subtitle != null && subtitle!.isNotEmpty)
                        Text(subtitle!, style: TextStyle(color: colors.muted, fontSize: 12)),
                    ],
                  ),
                ),
                if (selected) Icon(LucideIcons.check, size: 16, color: colors.accent),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
