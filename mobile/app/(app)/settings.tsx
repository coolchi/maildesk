import { useState } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  StyleSheet,
  ScrollView,
  Alert,
  ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { LogOut, Info } from 'lucide-react-native';
import { useAuth } from '../../src/contexts/AuthContext';
import { Header, Card, Avatar, Button, Badge } from '../../src/components';
import { colors, spacing, fontSize, fontWeight, borderRadius } from '../../src/theme';

export default function SettingsScreen() {
  const { user, currentWorkspace, logout } = useAuth();
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  const handleLogout = () => {
    Alert.alert(
      'Sign Out',
      'Are you sure you want to sign out?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Sign Out',
          style: 'destructive',
          onPress: async () => {
            setIsLoggingOut(true);
            try {
              await logout();
              router.replace('/(auth)/login');
            } finally {
              setIsLoggingOut(false);
            }
          },
        },
      ]
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <Header title="Settings" />

      <ScrollView style={styles.content}>
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Account</Text>
          <Card noPadding>
            <View style={styles.profileRow}>
              <Avatar name={user?.name || user?.email || '?'} size={56} />
              <View style={styles.profileInfo}>
                <Text style={styles.profileName}>{user?.name || 'User'}</Text>
                <Text style={styles.profileEmail}>{user?.email}</Text>
              </View>
            </View>
          </Card>
        </View>

        {currentWorkspace && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Workspace</Text>
            <Card noPadding>
              <View style={styles.infoRow}>
                <Text style={styles.infoLabel}>Name</Text>
                <Text style={styles.infoValue}>{currentWorkspace.name}</Text>
              </View>
              <View style={styles.divider} />
              <View style={styles.infoRow}>
                <Text style={styles.infoLabel}>Plan</Text>
                <Badge status={currentWorkspace.plan?.toLowerCase() === 'trial' ? 'trial' : 'active'} label={currentWorkspace.plan} />
              </View>
              <View style={styles.divider} />
              <View style={styles.infoRow}>
                <Text style={styles.infoLabel}>Product</Text>
                <Text style={styles.infoValue}>{currentWorkspace.product}</Text>
              </View>
              <View style={styles.divider} />
              <View style={styles.infoRow}>
                <Text style={styles.infoLabel}>Mail Provider</Text>
                <View style={styles.providerStatus}>
                  <Text style={styles.infoValue}>{currentWorkspace.providerName}</Text>
                  <View style={[styles.statusDot, currentWorkspace.providerOk ? styles.statusOk : styles.statusError]} />
                </View>
              </View>
              {currentWorkspace.host && (
                <>
                  <View style={styles.divider} />
                  <View style={styles.infoRow}>
                    <Text style={styles.infoLabel}>Host</Text>
                    <Text style={styles.infoValue}>{currentWorkspace.host}</Text>
                  </View>
                </>
              )}
            </Card>
          </View>
        )}

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>App</Text>
          <Card noPadding>
            <View style={styles.infoRow}>
              <Text style={styles.infoLabel}>Version</Text>
              <Text style={styles.infoValue}>1.0.0</Text>
            </View>
          </Card>
        </View>

        <View style={styles.section}>
          <Button
            title={isLoggingOut ? '' : 'Sign Out'}
            onPress={handleLogout}
            variant="danger"
            fullWidth
            disabled={isLoggingOut}
            icon={isLoggingOut ? <ActivityIndicator color={colors.status.error.text} size="small" /> : <LogOut size={16} color={colors.status.error.text} strokeWidth={2} />}
          />
        </View>

        <View style={styles.footer}>
          <Text style={styles.footerText}>MailDesk Mobile</Text>
          <Text style={styles.footerSubtext}>© 2024 MailDesk</Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background.primary,
  },
  content: {
    flex: 1,
  },
  section: {
    marginTop: spacing[6],
    paddingHorizontal: spacing[4],
  },
  sectionTitle: {
    fontSize: fontSize.sm,
    fontWeight: fontWeight.semibold,
    color: colors.text.muted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: spacing[2],
    fontFamily: 'Inter_600SemiBold',
  },
  profileRow: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: spacing[4],
  },
  profileInfo: {
    flex: 1,
    marginLeft: spacing[3.5],
  },
  profileName: {
    fontSize: fontSize.lg,
    fontWeight: fontWeight.semibold,
    color: colors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  profileEmail: {
    fontSize: fontSize.sm,
    color: colors.text.muted,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3.5],
  },
  infoLabel: {
    fontSize: fontSize.base,
    color: colors.text.secondary,
    fontFamily: 'Inter_400Regular',
  },
  infoValue: {
    fontSize: fontSize.base,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  providerStatus: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  statusDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginLeft: spacing[2],
  },
  statusOk: {
    backgroundColor: colors.status.success.text,
  },
  statusError: {
    backgroundColor: colors.status.error.text,
  },
  divider: {
    height: 1,
    backgroundColor: colors.zinc[100],
    marginHorizontal: spacing[4],
  },
  footer: {
    alignItems: 'center',
    paddingVertical: spacing[8],
  },
  footerText: {
    fontSize: fontSize.sm,
    color: colors.text.placeholder,
    fontFamily: 'Inter_400Regular',
  },
  footerSubtext: {
    fontSize: fontSize.xs,
    color: colors.zinc[300],
    marginTop: spacing[1],
    fontFamily: 'Inter_400Regular',
  },
});
