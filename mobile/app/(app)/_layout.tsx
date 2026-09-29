import { Tabs } from 'expo-router';
import { Text, View, StyleSheet } from 'react-native';

interface TabIconProps {
  focused: boolean;
  icon: string;
  label: string;
}

function TabIcon({ focused, icon, label }: TabIconProps) {
  return (
    <View style={styles.tabIcon}>
      <Text style={[styles.icon, focused && styles.iconFocused]}>{icon}</Text>
      <Text style={[styles.label, focused && styles.labelFocused]}>{label}</Text>
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
            <TabIcon focused={focused} icon="📥" label="Inbox" />
          ),
        }}
      />
      <Tabs.Screen
        name="compose"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} icon="✏️" label="Compose" />
          ),
        }}
      />
      <Tabs.Screen
        name="contacts"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} icon="👥" label="Contacts" />
          ),
        }}
      />
      <Tabs.Screen
        name="workspace"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} icon="🏢" label="Workspace" />
          ),
        }}
      />
      <Tabs.Screen
        name="settings"
        options={{
          tabBarIcon: ({ focused }) => (
            <TabIcon focused={focused} icon="⚙️" label="Settings" />
          ),
        }}
      />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  tabBar: {
    backgroundColor: '#FFFFFF',
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
    height: 80,
    paddingTop: 8,
    paddingBottom: 24,
  },
  tabIcon: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  icon: {
    fontSize: 24,
    marginBottom: 2,
  },
  iconFocused: {
    transform: [{ scale: 1.1 }],
  },
  label: {
    fontSize: 11,
    color: '#6B7280',
  },
  labelFocused: {
    color: '#3B82F6',
    fontWeight: '600',
  },
});
