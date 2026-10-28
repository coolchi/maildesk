import React, { useState } from 'react';
import {
  View,
  TextInput,
  Text,
  StyleSheet,
  TextInputProps,
  ViewStyle,
} from 'react-native';
import { colors, borderRadius, spacing, fontSize, fontWeight } from '../theme';
import { getShadow } from '../theme/shadows';

interface InputProps extends Omit<TextInputProps, 'style'> {
  label?: string;
  error?: string;
  containerStyle?: ViewStyle;
}

export const Input: React.FC<InputProps> = ({
  label,
  error,
  containerStyle,
  ...props
}) => {
  const [isFocused, setIsFocused] = useState(false);

  return (
    <View style={[styles.container, containerStyle]}>
      {label && <Text style={styles.label}>{label}</Text>}
      <TextInput
        style={[
          styles.input,
          isFocused && styles.inputFocused,
          error && styles.inputError,
        ]}
        placeholderTextColor={colors.text.placeholder}
        onFocus={() => setIsFocused(true)}
        onBlur={() => setIsFocused(false)}
        {...props}
      />
      {error && <Text style={styles.error}>{error}</Text>}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    marginBottom: spacing[4],
  },
  label: {
    fontSize: fontSize.sm,
    fontWeight: fontWeight.medium,
    color: colors.text.secondary,
    marginBottom: spacing[1.5],
  },
  input: {
    backgroundColor: colors.background.input,
    borderWidth: 1,
    borderColor: colors.border.primary,
    borderRadius: borderRadius.lg,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[3],
    fontSize: fontSize.base,
    color: colors.text.primary,
    ...getShadow('sm'),
  },
  inputFocused: {
    borderColor: colors.border.focus,
    shadowColor: colors.brand.primary,
    shadowOpacity: 0.1,
    shadowRadius: 4,
  },
  inputError: {
    borderColor: colors.status.error.text,
  },
  error: {
    fontSize: fontSize.xs,
    color: colors.status.error.text,
    marginTop: spacing[1],
  },
});

export default Input;
