import React from 'react';
import {
  TouchableOpacity,
  Text,
  StyleSheet,
  ActivityIndicator,
  ViewStyle,
  TextStyle,
} from 'react-native';
import { colors, borderRadius, spacing, fontSize, fontWeight } from '../theme';
import { getShadow } from '../theme/shadows';

export type ButtonVariant = 'primary' | 'ghost' | 'solid' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

interface ButtonProps {
  title: string;
  onPress: () => void;
  variant?: ButtonVariant;
  size?: ButtonSize;
  disabled?: boolean;
  loading?: boolean;
  fullWidth?: boolean;
  icon?: React.ReactNode;
  style?: ViewStyle;
}

export const Button: React.FC<ButtonProps> = ({
  title,
  onPress,
  variant = 'primary',
  size = 'md',
  disabled = false,
  loading = false,
  fullWidth = false,
  icon,
  style,
}) => {
  const isDisabled = disabled || loading;

  return (
    <TouchableOpacity
      style={[
        styles.base,
        styles[variant],
        styles[`size_${size}`],
        fullWidth && styles.fullWidth,
        isDisabled && styles.disabled,
        style,
      ]}
      onPress={onPress}
      disabled={isDisabled}
      activeOpacity={0.7}
    >
      {loading ? (
        <ActivityIndicator
          size="small"
          color={variant === 'primary' ? colors.white : colors.brand.primary}
        />
      ) : (
        <>
          {icon}
          <Text
            style={[
              styles.text,
              styles[`text_${variant}`],
              styles[`text_${size}`],
              icon ? styles.textWithIcon : undefined,
            ]}
          >
            {title}
          </Text>
        </>
      )}
    </TouchableOpacity>
  );
};

const styles = StyleSheet.create({
  base: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: borderRadius.full,
  },

  // Variants (matching .md-btn-primary, .md-btn-ghost, .md-btn-solid)
  primary: {
    backgroundColor: colors.brand.primary,
    ...getShadow('sm'),
  },
  ghost: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border.primary,
  },
  solid: {
    backgroundColor: colors.zinc[900],
    ...getShadow('sm'),
  },
  danger: {
    backgroundColor: colors.status.error.bg,
    borderWidth: 1,
    borderColor: colors.status.error.ring,
  },

  // Sizes
  size_sm: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1.5],
    gap: spacing[1.5],
  },
  size_md: {
    paddingHorizontal: spacing[3.5],
    paddingVertical: spacing[2],
    gap: spacing[2],
  },
  size_lg: {
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    gap: spacing[2],
  },

  fullWidth: {
    width: '100%',
  },

  disabled: {
    opacity: 0.5,
  },

  // Text styles
  text: {
    fontWeight: fontWeight.medium,
  },
  text_primary: {
    color: colors.white,
  },
  text_ghost: {
    color: colors.text.secondary,
  },
  text_solid: {
    color: colors.white,
  },
  text_danger: {
    color: colors.status.error.text,
  },
  text_sm: {
    fontSize: fontSize.xs,
  },
  text_md: {
    fontSize: fontSize.sm,
  },
  text_lg: {
    fontSize: fontSize.base,
  },
  textWithIcon: {
    marginLeft: spacing[1],
  },
});

export default Button;
