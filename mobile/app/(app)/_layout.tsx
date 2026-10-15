import { Tabs } from 'expo-router';
import { Text, View, StyleSheet } from 'react-native';
import { Inbox, PenSquare, Users, Building2, Settings } from 'lucide-react-native';
import { colors } from '../../src/theme';
import type { LucideIcon } from 'lucide-react-native';

interface TabIconProps {
  focused: boolean;
  Icon: LucideIcon;
  label: string;
}

function TabIcon({ focused, Icon, label }: TabIconProps) {
  return (
    <View style={styles.tabIcon}>
      <Icon
        size={22}
        strokeWidth={focused ? 2.35 : 1.75}
        color={focused ? colors.brand.primary : colors.text.muted}
      />
      <Text
        style={[
          styles.label,
          { fontFamily: 'Inter_500Medium' },
          focused && styles.labelFocused,
        ]}
      >
        {label}
      </Text>
    </View>
  );
}

export default function AppLayout() {
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarStyle: styles.tabBar,
        tabBarShowLabel: false,
      }}
    >
      <Tabs.Screen
        name="inbox"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} Icon={Inbox} label="Inbox" />
          ),
        }}
      />
      <Tabs.Screen
        name="compose"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} Icon={PenSquare} label="Compose" />
          ),
        }}
      />
      <Tabs.Screen
        name="contacts"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} Icon={Users} label="Contacts" />
          ),
        }}
      />
      <Tabs.Screen
        name="workspace"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} Icon={Building2} label="Workspace" />
          ),
        }}
      />
      <Tabs.Screen
        name="settings"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} Icon={Settings} label="Settings" />
          ),
        }}
      />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  tabBar: {
    backgroundColor: colors.background.secondary,
    borderTopWidth: 1,
    borderTopColor: colors.border.primary,
    height: 80,
    paddingTop: 8,
    paddingBottom: 24,
  },
  tabIcon: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
  },
  label: {
    fontSize: 10,
    color: colors.text.muted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  labelFocused: {
    color: colors.brand.primary,
  },
});
