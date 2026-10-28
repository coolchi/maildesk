import React from 'react';
import {
  TouchableOpacity,
  View,
  Text,
  StyleSheet,
  ViewStyle,
} from 'react-native';
import { colors, borderRadius, spacing, fontSize, fontWeight } from '../theme';
import { getShadow } from '../theme/shadows';

interface ListItemProps {
  title: string;
  subtitle?: string;
  meta?: string;
  leftContent?: React.ReactNode;
  rightContent?: React.ReactNode;
  onPress?: () => void;
  highlighted?: boolean;
  style?: ViewStyle;
}

export const ListItem: React.FC<ListItemProps> = ({
  title,
  subtitle,
  meta,
  leftContent,
  rightContent,
  onPress,
  highlighted = false,
  style,
}) => {
  const Container = onPress ? TouchableOpacity : View;

  return (
    <Container
      style={[
        styles.container,
        highlighted && styles.highlighted,
        style,
      ]}
      onPress={onPress}
      activeOpacity={onPress ? 0.7 : 1}
    >
      {leftContent && <View style={styles.leftContent}>{leftContent}</View>}
      <View style={styles.content}>
        <View style={styles.header}>
          <Text
            style={[styles.title, highlighted && styles.titleHighlighted]}
            numberOfLines={1}
          >
            {title}
          </Text>
          {meta && <Text style={styles.meta}>{meta}</Text>}
        </View>
        {subtitle && (
          <Text style={styles.subtitle} numberOfLines={2}>
            {subtitle}
          </Text>
        )}
      </View>
      {rightContent && <View style={styles.rightContent}>{rightContent}</View>}
    </Container>
  );
};

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    backgroundColor: colors.background.secondary,
    marginHorizontal: spacing[3],
    marginVertical: spacing[1],
    padding: spacing[3.5],
    borderRadius: borderRadius.xl,
    ...getShadow('sm'),
  },
  highlighted: {
    backgroundColor: '#fefce8', // yellow-50 for unread
    borderLeftWidth: 3,
    borderLeftColor: colors.brand.primary,
  },
  leftContent: {
    marginRight: spacing[3],
  },
  content: {
    flex: 1,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing[1],
  },
  title: {
    flex: 1,
    fontSize: fontSize.base,
    color: colors.text.primary,
    marginRight: spacing[2],
  },
  titleHighlighted: {
    fontWeight: fontWeight.semibold,
  },
  meta: {
    fontSize: fontSize.xs,
    color: colors.text.placeholder,
  },
  subtitle: {
    fontSize: fontSize.sm,
    color: colors.text.muted,
    lineHeight: fontSize.sm * 1.4,
  },
  rightContent: {
    marginLeft: spacing[2],
  },
});

export default ListItem;
