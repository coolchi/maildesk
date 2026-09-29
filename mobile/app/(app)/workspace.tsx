import { useState } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  StyleSheet,
  FlatList,
  ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { useAuth } from '../../src/contexts/AuthContext';
import type { Workspace } from '../../src/api/types';

const WORKSPACE_COLORS: Record<string, { bg: string; text: string }> = {
  cyan: { bg: '#CFFAFE', text: '#0891B2' },
  violet: { bg: '#EDE9FE', text: '#7C3AED' },
  emerald: { bg: '#D1FAE5', text: '#059669' },
  amber: { bg: '#FEF3C7', text: '#D97706' },
};

export default function WorkspaceScreen() {
  const { workspaces, currentWorkspace, switchWorkspace, refreshUser } = useAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [switchingId, setSwitchingId] = useState<number | null>(null);

  const handleSwitchWorkspace = async (workspace: Workspace) => {
    if (workspace.id === currentWorkspace?.id) return;

    setSwitchingId(workspace.id);
    try {
      await switchWorkspace(workspace.id);
      router.replace('/(app)/inbox');
    } finally {
      setSwitchingId(null);
    }
  };

  const handleRefresh = async () => {
    setIsLoading(true);
    await refreshUser();
    setIsLoading(false);
  };

  const renderWorkspace = ({ item }: { item: Workspace }) => {
    const isActive = item.id === currentWorkspace?.id;
    const colors = WORKSPACE_COLORS[item.color] || WORKSPACE_COLORS.cyan;

    return (
      <TouchableOpacity
        style={[styles.workspaceItem, isActive && styles.workspaceActive]}
        onPress={() => handleSwitchWorkspace(item)}
        disabled={switchingId !== null}
      >
        <View style={[styles.workspaceIcon, { backgroundColor: colors.bg }]}>
          <Text style={[styles.workspaceInitial, { color: colors.text }]}>
            {item.name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <View style={styles.workspaceInfo}>
          <Text style={styles.workspaceName}>{item.name}</Text>
          <Text style={styles.workspaceDetails}>
            {item.plan} • {item.product}
          </Text>
          {item.subdomain && (
            <Text style={styles.workspaceHost}>{item.host}</Text>
          )}
        </View>
        {switchingId === item.id ? (
          <ActivityIndicator size="small" color="#3B82F6" />
        ) : isActive ? (
          <View style={styles.activeIndicator}>
            <Text style={styles.activeText}>✓</Text>
          </View>
        ) : null}
      </TouchableOpacity>
    );
  };

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Text style={styles.emptyIcon}>🏢</Text>
      <Text style={styles.emptyText}>No workspaces available</Text>
    </View>
  );

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>Workspaces</Text>
        <TouchableOpacity style={styles.refreshButton} onPress={handleRefresh} disabled={isLoading}>
          {isLoading ? (
            <ActivityIndicator size="small" color="#3B82F6" />
          ) : (
            <Text style={styles.refreshText}>↻</Text>
          )}
        </TouchableOpacity>
      </View>

      {currentWorkspace && (
        <View style={styles.currentSection}>
          <Text style={styles.sectionTitle}>Current Workspace</Text>
          <View style={styles.currentWorkspace}>
            <View style={[styles.currentIcon, { backgroundColor: WORKSPACE_COLORS[currentWorkspace.color]?.bg || '#CFFAFE' }]}>
              <Text style={[styles.currentInitial, { color: WORKSPACE_COLORS[currentWorkspace.color]?.text || '#0891B2' }]}>
                {currentWorkspace.name.charAt(0).toUpperCase()}
              </Text>
            </View>
            <View style={styles.currentInfo}>
              <Text style={styles.currentName}>{currentWorkspace.name}</Text>
              <Text style={styles.currentDetails}>
                {currentWorkspace.plan} • {currentWorkspace.providerName}
              </Text>
            </View>
          </View>
        </View>
      )}

      <Text style={styles.sectionTitle}>All Workspaces</Text>

      <FlatList
        data={workspaces}
        renderItem={renderWorkspace}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={workspaces.length === 0 ? styles.listEmpty : styles.list}
        ListEmptyComponent={renderEmpty}
      />
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
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  title: {
    fontSize: 20,
    fontWeight: '700',
    color: '#111827',
  },
  refreshButton: {
    padding: 8,
  },
  refreshText: {
    fontSize: 20,
    color: '#3B82F6',
  },
  currentSection: {
    backgroundColor: '#FFFFFF',
    paddingBottom: 16,
    marginBottom: 8,
  },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '600',
    color: '#6B7280',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    paddingHorizontal: 16,
    paddingVertical: 12,
  },
  currentWorkspace: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
  },
  currentIcon: {
    width: 56,
    height: 56,
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 14,
  },
  currentInitial: {
    fontSize: 24,
    fontWeight: '600',
  },
  currentInfo: {
    flex: 1,
  },
  currentName: {
    fontSize: 18,
    fontWeight: '600',
    color: '#111827',
  },
  currentDetails: {
    fontSize: 14,
    color: '#6B7280',
    marginTop: 2,
  },
  list: {
    paddingBottom: 16,
  },
  listEmpty: {
    flex: 1,
  },
  workspaceItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    marginHorizontal: 12,
    marginVertical: 4,
    padding: 14,
    borderRadius: 12,
    borderWidth: 2,
    borderColor: 'transparent',
  },
  workspaceActive: {
    borderColor: '#3B82F6',
    backgroundColor: '#EFF6FF',
  },
  workspaceIcon: {
    width: 44,
    height: 44,
    borderRadius: 10,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  workspaceInitial: {
    fontSize: 18,
    fontWeight: '600',
  },
  workspaceInfo: {
    flex: 1,
  },
  workspaceName: {
    fontSize: 15,
    fontWeight: '600',
    color: '#111827',
  },
  workspaceDetails: {
    fontSize: 13,
    color: '#6B7280',
    marginTop: 2,
  },
  workspaceHost: {
    fontSize: 12,
    color: '#9CA3AF',
    marginTop: 2,
  },
  activeIndicator: {
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: '#3B82F6',
    justifyContent: 'center',
    alignItems: 'center',
  },
  activeText: {
    color: '#FFFFFF',
    fontWeight: '600',
    fontSize: 14,
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
});
