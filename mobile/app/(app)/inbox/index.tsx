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
import { inboxApi } from '../../../src/api/inbox';
import { useAuth } from '../../../src/contexts/AuthContext';
import type { Thread, InboxFolder } from '../../../src/api/types';

const FOLDERS: { key: InboxFolder; label: string; icon: string }[] = [
  { key: 'inbox', label: 'Inbox', icon: '📥' },
  { key: 'archive', label: 'Archive', icon: '📦' },
  { key: 'spam', label: 'Spam', icon: '⚠️' },
  { key: 'trash', label: 'Trash', icon: '🗑️' },
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
    <TouchableOpacity
      style={[styles.threadItem, item.unread && styles.threadUnread]}
      onPress={() => handleThreadPress(item.id)}
    >
      <View style={styles.threadHeader}>
        <Text style={[styles.threadFrom, item.unread && styles.textBold]} numberOfLines={1}>
          {item.from_name || item.from_email}
        </Text>
        <Text style={styles.threadTime}>{item.updated}</Text>
      </View>
      <Text style={[styles.threadSubject, item.unread && styles.textBold]} numberOfLines={1}>
        {item.subject || '(no subject)'}
      </Text>
      <Text style={styles.threadSnippet} numberOfLines={2}>
        {item.snippet}
      </Text>
      <View style={styles.threadMeta}>
        {item.message_count > 1 && (
          <Text style={styles.threadCount}>{item.message_count} messages</Text>
        )}
        {item.is_archived && <Text style={styles.threadLabel}>📦 Archived</Text>}
        {item.is_spam && <Text style={styles.threadLabel}>⚠️ Spam</Text>}
      </View>
    </TouchableOpacity>
  );

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Text style={styles.emptyIcon}>📭</Text>
      <Text style={styles.emptyText}>No conversations in {folder}</Text>
    </View>
  );

  const renderFooter = () => {
    if (!isLoadingMore) return null;
    return (
      <View style={styles.footer}>
        <ActivityIndicator size="small" color="#3B82F6" />
      </View>
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>
          {currentWorkspace?.name || 'Inbox'}
        </Text>
        {unreadCount > 0 && (
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{unreadCount}</Text>
          </View>
        )}
      </View>

      <View style={styles.folderTabs}>
        {FOLDERS.map((f) => (
          <TouchableOpacity
            key={f.key}
            style={[styles.folderTab, folder === f.key && styles.folderTabActive]}
            onPress={() => setFolder(f.key)}
          >
            <Text style={styles.folderIcon}>{f.icon}</Text>
            <Text style={[styles.folderLabel, folder === f.key && styles.folderLabelActive]}>
              {f.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {isLoading ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color="#3B82F6" />
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
            <RefreshControl refreshing={isRefreshing} onRefresh={handleRefresh} />
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
    backgroundColor: '#F9FAFB',
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 12,
    backgroundColor: '#FFFFFF',
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  title: {
    fontSize: 20,
    fontWeight: '700',
    color: '#111827',
  },
  badge: {
    backgroundColor: '#3B82F6',
    borderRadius: 12,
    paddingHorizontal: 8,
    paddingVertical: 2,
    marginLeft: 8,
  },
  badgeText: {
    color: '#FFFFFF',
    fontSize: 12,
    fontWeight: '600',
  },
  folderTabs: {
    flexDirection: 'row',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 8,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  folderTab: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 8,
    borderRadius: 8,
  },
  folderTabActive: {
    backgroundColor: '#EFF6FF',
  },
  folderIcon: {
    fontSize: 14,
    marginRight: 4,
  },
  folderLabel: {
    fontSize: 13,
    color: '#6B7280',
  },
  folderLabelActive: {
    color: '#3B82F6',
    fontWeight: '600',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  list: {
    paddingVertical: 8,
  },
  listEmpty: {
    flex: 1,
  },
  threadItem: {
    backgroundColor: '#FFFFFF',
    marginHorizontal: 12,
    marginVertical: 4,
    padding: 14,
    borderRadius: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.03,
    shadowRadius: 4,
    elevation: 1,
  },
  threadUnread: {
    backgroundColor: '#FEFCE8',
    borderLeftWidth: 3,
    borderLeftColor: '#3B82F6',
  },
  threadHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 4,
  },
  threadFrom: {
    flex: 1,
    fontSize: 15,
    color: '#111827',
    marginRight: 8,
  },
  threadTime: {
    fontSize: 12,
    color: '#9CA3AF',
  },
  threadSubject: {
    fontSize: 14,
    color: '#374151',
    marginBottom: 4,
  },
  threadSnippet: {
    fontSize: 13,
    color: '#6B7280',
    lineHeight: 18,
  },
  threadMeta: {
    flexDirection: 'row',
    marginTop: 8,
  },
  threadCount: {
    fontSize: 12,
    color: '#9CA3AF',
    marginRight: 8,
  },
  threadLabel: {
    fontSize: 12,
    color: '#6B7280',
    marginRight: 8,
  },
  textBold: {
    fontWeight: '600',
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  emptyIcon: {
    fontSize: 48,
    marginBottom: 16,
  },
  emptyText: {
    fontSize: 16,
    color: '#6B7280',
    textAlign: 'center',
  },
  footer: {
    paddingVertical: 20,
    alignItems: 'center',
  },
});
