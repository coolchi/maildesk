import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Linking } from 'react-native';

interface PaymentBannerProps {
  message: string;
  onDismiss?: () => void;
}

export const PaymentBanner: React.FC<PaymentBannerProps> = ({ message, onDismiss }) => {
  const handleRenew = async () => {
    try {
      await Linking.openURL('https://maildesk.ng/settings/billing');
    } catch {
      // URL could not be opened
    }
  };

  return (
    <View style={styles.container}>
      <View style={styles.content}>
        <Text style={styles.message}>{message}</Text>
        <TouchableOpacity style={styles.button} onPress={handleRenew}>
          <Text style={styles.buttonText}>Renew Plan</Text>
        </TouchableOpacity>
      </View>
      {onDismiss && (
        <TouchableOpacity style={styles.dismissButton} onPress={onDismiss}>
          <Text style={styles.dismissText}>×</Text>
        </TouchableOpacity>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    backgroundColor: '#fef3cd',
    borderBottomWidth: 1,
    borderBottomColor: '#ffc107',
    paddingHorizontal: 16,
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'flex-start',
  },
  content: {
    flex: 1,
  },
  message: {
    color: '#856404',
    fontSize: 14,
    lineHeight: 20,
    marginBottom: 8,
  },
  button: {
    backgroundColor: '#856404',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 6,
    alignSelf: 'flex-start',
  },
  buttonText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '600',
  },
  dismissButton: {
    marginLeft: 8,
    padding: 4,
  },
  dismissText: {
    color: '#856404',
    fontSize: 20,
    fontWeight: 'bold',
  },
});
