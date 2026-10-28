import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { colors, borderRadius, spacing, fontSize, fontWeight } from '../theme';

export type BadgeStatus =
  | 'delivered'
  | 'sent'
  | 'bounced'
  | 'complained'
  | 'suppressed'
  | 'received'
  | 'pending'
  | 'verified'
  | 'failing'
  | 'failed'
  | 'published'
  | 'draft'
  | 'scheduled'
  | 'subscribed'
  | 'unsubscribed'
  | 'queued'
  | 'sending'
  | 'enabled'
  | 'disabled'
  | 'paused'
  | 'active'
  | 'inactive'
  | 'trial'
  | 'past_due'
  | 'suspended'
  | 'spam';

interface BadgeProps {
  status: BadgeStatus | string;
  label?: string;
  style?: ViewStyle;
}

const statusStyles: Record<string, { bg: string; text: string }> = {
  delivered: { bg: colors.status.success.bg, text: colors.status.success.text },
  sent: { bg: colors.status.info.bg, text: colors.status.info.text },
  bounced: { bg: colors.status.error.bg, text: colors.status.error.text },
  complained: { bg: colors.status.error.bg, text: colors.status.error.text },
  suppressed: { bg: colors.status.neutral.bg, text: colors.status.neutral.text },
  received: { bg: colors.status.sky.bg, text: colors.status.sky.text },
  pending: { bg: colors.status.warning.bg, text: colors.status.warning.text },
  verified: { bg: colors.status.success.bg, text: colors.status.success.text },
  failing: { bg: colors.status.error.bg, text: colors.status.error.text },
  failed: { bg: colors.status.error.bg, text: colors.status.error.text },
  published: { bg: colors.status.info.bg, text: colors.status.info.text },
  draft: { bg: colors.status.neutral.bg, text: colors.status.neutral.text },
  scheduled: { bg: colors.status.purple.bg, text: colors.status.purple.text },
  subscribed: { bg: colors.status.success.bg, text: colors.status.success.text },
  unsubscribed: { bg: colors.status.neutral.bg, text: colors.status.neutral.text },
  queued: { bg: colors.status.warning.bg, text: colors.status.warning.text },
  sending: { bg: colors.status.purple.bg, text: colors.status.purple.text },
  enabled: { bg: colors.status.success.bg, text: colors.status.success.text },
  disabled: { bg: colors.status.neutral.bg, text: colors.status.neutral.text },
  paused: { bg: colors.status.warning.bg, text: colors.status.warning.text },
  active: { bg: colors.status.success.bg, text: colors.status.success.text },
  inactive: { bg: colors.status.neutral.bg, text: colors.status.neutral.text },
  trial: { bg: colors.status.sky.bg, text: colors.status.sky.text },
  past_due: { bg: colors.status.warning.bg, text: colors.status.warning.text },
  suspended: { bg: colors.status.error.bg, text: colors.status.error.text },
  spam: { bg: colors.status.error.bg, text: colors.status.error.text },
};

const statusLabels: Record<string, string> = {
  enabled: 'Enabled',
  disabled: 'Disabled',
  business_owner: 'Admin',
  admin: 'Admin',
  staff: 'Staff',
  developer: 'Developer',
  active: 'Active',
  inactive: 'Inactive',
  pending: 'Pending',
  trial: 'Trial',
  past_due: 'Past due',
  suspended: 'Suspended',
};

export const Badge: React.FC<BadgeProps> = ({ status, label, style }) => {
  const statusStyle = statusStyles[status] || statusStyles.suppressed;
  const displayLabel = label || statusLabels[status] || status;

  return (
    <View style={[styles.badge, { backgroundColor: statusStyle.bg }, style]}>
      <Text style={[styles.text, { color: statusStyle.text }]}>
        {displayLabel.charAt(0).toUpperCase() + displayLabel.slice(1)}
      </Text>
    </View>
  );
};

const styles = StyleSheet.create({
  badge: {
    paddingHorizontal: spacing[2],
    paddingVertical: spacing[0.5],
    borderRadius: borderRadius.full,
    alignSelf: 'flex-start',
  },
  text: {
    fontSize: 11,
    fontWeight: fontWeight.medium,
  },
});

export default Badge;
