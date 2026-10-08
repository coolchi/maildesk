import { useState, useCallback, useEffect } from 'react';
import {
  View,
  Text,
  FlatList,
  TouchableOpacity,
  StyleSheet,
  RefreshControl,
  ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Inbox, Archive, ShieldAlert, Trash2, Mail } from 'lucide-react-native';
import { inboxApi } from '../../../src/api/inbox';
import { useAuth } from '../../../src/contexts/AuthContext';
import { Header, ListItem, Card } from '../../../src/components';
import { colors, spacing, fontSize, fontWeight, borderRadius } from '../../../src/theme';
import type { Thread, InboxFolder } from '../../../src/api/types';
import type { LucideIcon } from 'lucide-react-native';

const FOLDERS: { key: InboxFolder; label: string; Icon: LucideIcon }[] = [
  { key: 'inbox', label: 'Inbox', Icon: Inbox },
  { key: 'archive', label: 'Archive', Icon: Archive },
  { key: 'spam', label: 'Spam', Icon: ShieldAlert },
  { key: 'trash', label: 'Trash', Icon: Trash2 },
];

export default function InboxScreen() {
  const { currentWorkspace } = useAuth();
  const [threads, setThreads] = useState<Thread[]>([]);
  const [folder, setFolder] = useState<InboxFolder>('inbox');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  const loadThreads = useCallback(async (pageNum: number, refresh = false) => {
    if (!currentWorkspace) return;

    try {
      const response = await inboxApi.getThreads({ folder, page: pageNum, per_page: 20 });
      
      if (refresh || pageNum === 1) {
        setThreads(response.threads);
      } else {
        setThreads(prev => [...prev, ...response.threads]);
      }
      
      setHasMore(response.pagination.current_page < response.pagination.last_page);
      setUnreadCount(response.unread_count);
    } catch (error) {
      console.error('Failed to load threads:', error);
    }
  }, [folder, currentWorkspace]);

  useEffect(() => {
    setIsLoading(true);
    setPage(1);
    loadThreads(1, true).finally(() => setIsLoading(false));
  }, [folder, loadThreads]);

  const handleRefresh = useCallback(async () => {
    setIsRefreshing(true);
    setPage(1);
    await loadThreads(1, true);
    setIsRefreshing(false);
  }, [loadThreads]);

  const handleLoadMore = useCallback(async () => {
    if (isLoadingMore || !hasMore) return;
    
    setIsLoadingMore(true);
    const nextPage = page + 1;
    setPage(nextPage);
    await loadThreads(nextPage);
    setIsLoadingMore(false);
  }, [page, hasMore, isLoadingMore, loadThreads]);

  const handleThreadPress = (threadId: number) => {
    router.push(`/(app)/inbox/${threadId}`);
  };

  const renderThread = ({ item }: { item: Thread }) => (
    <ListItem
      title={item.from_name || item.from_email}
      subtitle={item.subject || '(no subject)'}
      meta={item.updated}
      highlighted={item.unread}
      onPress={() => handleThreadPress(item.id)}
      rightContent={
        item.message_count > 1 ? (
          <View style={styles.countBadge}>
            <Text style={styles.countText}>{item.message_count}</Text>
          </View>
        ) : undefined
      }
    />
  );

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Mail size={48} color={colors.text.muted} strokeWidth={1.5} />
      <Text style={styles.emptyText}>No conversations in {folder}</Text>
    </View>
  );

  const renderFooter = () => {
    if (!isLoadingMore) return null;
    return (
      <View style={styles.footer}>
        <ActivityIndicator size="small" color={colors.brand.primary} />
      </View>
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <Header
        title={currentWorkspace?.name || 'Inbox'}
        badge={unreadCount}
      />

      <View style={styles.folderTabs}>
        {FOLDERS.map((f) => (
          <TouchableOpacity
            key={f.key}
            style={[styles.folderTab, folder === f.key && styles.folderTabActive]}
            onPress={() => setFolder(f.key)}
          >
            <f.Icon
              size={16}
              color={folder === f.key ? colors.brand.primary : colors.text.muted}
              strokeWidth={folder === f.key ? 2 : 1.5}
            />
            <Text style={[styles.folderLabel, folder === f.key && styles.folderLabelActive]}>
              {f.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {isLoading ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color={colors.brand.primary} />
        </View>
      ) : (
        <FlatList
          data={threads}
          renderItem={renderThread}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={threads.length === 0 ? styles.listEmpty : styles.list}
          ListEmptyComponent={renderEmpty}
          ListFooterComponent={renderFooter}
          refreshControl={
            <RefreshControl
              refreshing={isRefreshing}
              onRefresh={handleRefresh}
              tintColor={colors.brand.primary}
            />
          }
          onEndReached={handleLoadMore}
          onEndReachedThreshold={0.3}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background.primary,
  },
  folderTabs: {
    flexDirection: 'row',
    backgroundColor: colors.background.secondary,
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[2],
    borderBottomWidth: 1,
    borderBottomColor: colors.border.primary,
  },
  folderTab: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing[2],
    borderRadius: borderRadius.md,
  },
  folderTabActive: {
    backgroundColor: colors.brand.primaryLight,
  },
  folderIconWrap: {
    marginRight: spacing[1],
  },
  folderLabel: {
    fontSize: fontSize.sm,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  folderLabelActive: {
    color: colors.brand.primary,
    fontWeight: fontWeight.semibold,
    fontFamily: 'Inter_600SemiBold',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  list: {
    paddingVertical: spacing[2],
  },
  listEmpty: {
    flex: 1,
  },
  countBadge: {
    backgroundColor: colors.zinc[100],
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[0.5],
    borderRadius: borderRadius.full,
  },
  countText: {
    fontSize: 11,
    color: colors.text.muted,
    fontFamily: 'Inter_500Medium',
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: spacing[10],
    gap: spacing[4],
  },
  emptyText: {
    fontSize: fontSize.base,
    color: colors.text.muted,
    textAlign: 'center',
    fontFamily: 'Inter_400Regular',
  },
  footer: {
    paddingVertical: spacing[5],
    alignItems: 'center',
  },
});
