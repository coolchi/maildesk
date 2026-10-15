import React from 'react';
import { View, StyleSheet, ViewStyle, ViewProps } from 'react-native';
import { colors, borderRadius, spacing } from '../theme';
import { getShadow } from '../theme/shadows';

interface CardProps extends ViewProps {
  children: React.ReactNode;
  style?: ViewStyle;
  noPadding?: boolean;
}

export const Card: React.FC<CardProps> = ({
  children,
  style,
  noPadding = false,
  ...props
}) => {
  return (
    <View
      style={[styles.card, !noPadding && styles.padding, style]}
      {...props}
    >
      {children}
    </View>
  );
};

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.background.secondary,
    borderRadius: borderRadius.xl,
    borderWidth: 1,
    borderColor: colors.border.primary,
    ...getShadow('base'),
  },
  padding: {
    padding: spacing[4],
  },
});

export default Card;
