import React from 'react';
import { View, Text, StyleSheet, ViewStyle } from 'react-native';
import { colors, borderRadius, fontSize, fontWeight } from '../theme';

interface AvatarProps {
  name: string;
  size?: number;
  style?: ViewStyle;
}

export const Avatar: React.FC<AvatarProps> = ({
  name,
  size = 44,
  style,
}) => {
  const initial = (name || '?').charAt(0).toUpperCase();

  return (
    <View
      style={[
        styles.container,
        {
          width: size,
          height: size,
          borderRadius: size / 2,
        },
        style,
      ]}
    >
      <Text style={[styles.initial, { fontSize: size * 0.4 }]}>{initial}</Text>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.brand.primary,
    justifyContent: 'center',
    alignItems: 'center',
  },
  initial: {
    color: colors.white,
    fontWeight: fontWeight.semibold,
  },
});

export default Avatar;
