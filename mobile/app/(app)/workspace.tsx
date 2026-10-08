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
import { Building2, Check, RotateCw } from 'lucide-react-native';
import { useAuth } from '../../src/contexts/AuthContext';
import { colors as wsColors, spacing, fontSize, fontWeight, borderRadius } from '../../src/theme';
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
          <ActivityIndicator size="small" color={wsColors.brand.primary} />
        ) : isActive ? (
          <View style={styles.activeIndicator}>
            <Check size={14} color={wsColors.white} strokeWidth={3} />
          </View>
        ) : null}
      </TouchableOpacity>
    );
  };

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Building2 size={48} color={wsColors.text.muted} strokeWidth={1.5} />
      <Text style={styles.emptyText}>No workspaces available</Text>
    </View>
  );

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.title}>Workspaces</Text>
        <TouchableOpacity style={styles.refreshButton} onPress={handleRefresh} disabled={isLoading}>
          {isLoading ? (
            <ActivityIndicator size="small" color={wsColors.brand.primary} />
          ) : (
            <RotateCw size={20} color={wsColors.brand.primary} strokeWidth={2} />
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
    backgroundColor: wsColors.background.primary,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: wsColors.background.secondary,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    borderBottomWidth: 1,
    borderBottomColor: wsColors.border.primary,
  },
  title: {
    fontSize: fontSize.xl,
    fontWeight: fontWeight.bold,
    color: wsColors.text.primary,
    fontFamily: 'Inter_700Bold',
  },
  refreshButton: {
    padding: spacing[2],
  },
  currentSection: {
    backgroundColor: wsColors.background.secondary,
    paddingBottom: spacing[4],
    marginBottom: spacing[2],
  },
  sectionTitle: {
    fontSize: fontSize.xs,
    fontWeight: fontWeight.semibold,
    color: wsColors.text.muted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    fontFamily: 'Inter_600SemiBold',
  },
  currentWorkspace: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
  },
  currentIcon: {
    width: 56,
    height: 56,
    borderRadius: borderRadius.xl,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: spacing[3.5],
  },
  currentInitial: {
    fontSize: fontSize['2xl'],
    fontWeight: fontWeight.semibold,
    fontFamily: 'Inter_600SemiBold',
  },
  currentInfo: {
    flex: 1,
  },
  currentName: {
    fontSize: fontSize.lg,
    fontWeight: fontWeight.semibold,
    color: wsColors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  currentDetails: {
    fontSize: fontSize.sm,
    color: wsColors.text.muted,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  list: {
    paddingBottom: spacing[4],
  },
  listEmpty: {
    flex: 1,
  },
  workspaceItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: wsColors.background.secondary,
    marginHorizontal: spacing[3],
    marginVertical: spacing[1],
    padding: spacing[3.5],
    borderRadius: borderRadius.xl,
    borderWidth: 2,
    borderColor: 'transparent',
  },
  workspaceActive: {
    borderColor: wsColors.brand.primary,
    backgroundColor: wsColors.brand.primaryLight,
  },
  workspaceIcon: {
    width: 44,
    height: 44,
    borderRadius: borderRadius.lg,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: spacing[3],
  },
  workspaceInitial: {
    fontSize: fontSize.lg,
    fontWeight: fontWeight.semibold,
    fontFamily: 'Inter_600SemiBold',
  },
  workspaceInfo: {
    flex: 1,
  },
  workspaceName: {
    fontSize: fontSize.base,
    fontWeight: fontWeight.semibold,
    color: wsColors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  workspaceDetails: {
    fontSize: fontSize.sm,
    color: wsColors.text.muted,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  workspaceHost: {
    fontSize: fontSize.xs,
    color: wsColors.text.placeholder,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  activeIndicator: {
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: wsColors.brand.primary,
    justifyContent: 'center',
    alignItems: 'center',
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
    color: wsColors.text.muted,
    textAlign: 'center',
    fontFamily: 'Inter_400Regular',
  },
});
