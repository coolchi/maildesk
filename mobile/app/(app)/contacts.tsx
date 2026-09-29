import { useState, useEffect, useCallback } from 'react';
import {
  View,
  Text,
  TextInput,
  FlatList,
  TouchableOpacity,
  StyleSheet,
  RefreshControl,
  ActivityIndicator,
  Modal,
  Alert,
  KeyboardAvoidingView,
  Platform,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { contactsApi, CreateContactParams } from '../../src/api/contacts';
import { isPaymentRequiredError, getPaymentRequiredMessage } from '../../src/api/client';
import { PaymentBanner, Header, Button, Input, Card, Avatar, Badge } from '../../src/components';
import { colors, spacing, fontSize, fontWeight, borderRadius } from '../../src/theme';
import { getShadow } from '../../src/theme/shadows';
import type { Contact } from '../../src/api/types';

export default function ContactsScreen() {
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [newContact, setNewContact] = useState<CreateContactParams>({
    email: '',
    first_name: '',
    last_name: '',
    company: '',
  });
  const [isAdding, setIsAdding] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);

  const loadContacts = useCallback(async (pageNum: number, refresh = false, searchTerm = search) => {
    try {
      const response = await contactsApi.getContacts({
        search: searchTerm || undefined,
        page: pageNum,
        per_page: 20,
      });

      if (refresh || pageNum === 1) {
        setContacts(response.contacts);
      } else {
        setContacts(prev => [...prev, ...response.contacts]);
      }

      setHasMore(response.pagination.current_page < response.pagination.last_page);
    } catch (error) {
      console.error('Failed to load contacts:', error);
    }
  }, [search]);

  useEffect(() => {
    setIsLoading(true);
    setPage(1);
    const timer = setTimeout(() => {
      loadContacts(1, true).finally(() => setIsLoading(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  const handleRefresh = useCallback(async () => {
    setIsRefreshing(true);
    setPage(1);
    await loadContacts(1, true);
    setIsRefreshing(false);
  }, [loadContacts]);

  const handleLoadMore = useCallback(async () => {
    if (isLoadingMore || !hasMore) return;

    setIsLoadingMore(true);
    const nextPage = page + 1;
    setPage(nextPage);
    await loadContacts(nextPage);
    setIsLoadingMore(false);
  }, [page, hasMore, isLoadingMore, loadContacts]);

  const handleAddContact = async () => {
    if (!newContact.email.trim()) {
      Alert.alert('Error', 'Please enter an email address');
      return;
    }

    setIsAdding(true);
    try {
      await contactsApi.createContact(newContact);
      setShowAddModal(false);
      setNewContact({ email: '', first_name: '', last_name: '', company: '' });
      await handleRefresh();
      Alert.alert('Success', 'Contact added');
    } catch (error: unknown) {
      if (isPaymentRequiredError(error)) {
        setShowAddModal(false);
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        const err = error as { response?: { data?: { message?: string } } };
        Alert.alert('Error', err.response?.data?.message || 'Failed to add contact');
      }
    } finally {
      setIsAdding(false);
    }
  };

  const renderContact = ({ item }: { item: Contact }) => (
    <View style={styles.contactItem}>
      <Avatar name={item.first_name || item.email} size={44} />
      <View style={styles.contactInfo}>
        <Text style={styles.contactName} numberOfLines={1}>
          {item.name || item.email}
        </Text>
        <Text style={styles.contactEmail} numberOfLines={1}>
          {item.email}
        </Text>
        {item.company && (
          <Text style={styles.contactCompany} numberOfLines={1}>
            🏢 {item.company}
          </Text>
        )}
      </View>
      <Badge status={item.status === 'subscribed' ? 'subscribed' : 'unsubscribed'} />
    </View>
  );

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Text style={styles.emptyIcon}>👥</Text>
      <Text style={styles.emptyText}>
        {search ? 'No contacts found' : 'No contacts yet'}
      </Text>
      {!search && (
        <Button
          title="Add your first contact"
          onPress={() => setShowAddModal(true)}
          variant="ghost"
          style={styles.emptyButton}
        />
      )}
    </View>
  );

  const renderFooter = () => {
    if (!isLoadingMore) return null;
    return (
      <View style={styles.footer}>
        <ActivityIndicator size="small" color={colors.brand.primary} />
      </View>
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      {paymentError && (
        <PaymentBanner
          message={paymentError}
          onDismiss={() => setPaymentError(null)}
        />
      )}
      <Header
        title="Contacts"
        rightContent={
          <Button
            title="+ Add"
            onPress={() => setShowAddModal(true)}
            size="sm"
          />
        }
      />

      <View style={styles.searchContainer}>
        <TextInput
          style={styles.searchInput}
          placeholder="Search contacts..."
          placeholderTextColor={colors.text.placeholder}
          value={search}
          onChangeText={setSearch}
          autoCapitalize="none"
        />
      </View>

      {isLoading ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color={colors.brand.primary} />
        </View>
      ) : (
        <FlatList
          data={contacts}
          renderItem={renderContact}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={contacts.length === 0 ? styles.listEmpty : styles.list}
          ListEmptyComponent={renderEmpty}
          ListFooterComponent={renderFooter}
          refreshControl={
            <RefreshControl
              refreshing={isRefreshing}
              onRefresh={handleRefresh}
              tintColor={colors.brand.primary}
            />
          }
          onEndReached={handleLoadMore}
          onEndReachedThreshold={0.3}
        />
      )}

      <Modal
        visible={showAddModal}
        animationType="slide"
        presentationStyle="pageSheet"
        onRequestClose={() => setShowAddModal(false)}
      >
        <SafeAreaView style={styles.modalContainer}>
          <KeyboardAvoidingView
            style={styles.modalContent}
            behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          >
            <View style={styles.modalHeader}>
              <TouchableOpacity onPress={() => setShowAddModal(false)}>
                <Text style={styles.modalCancel}>Cancel</Text>
              </TouchableOpacity>
              <Text style={styles.modalTitle}>Add Contact</Text>
              <TouchableOpacity onPress={handleAddContact} disabled={isAdding}>
                <Text style={[styles.modalSave, isAdding && styles.modalSaveDisabled]}>
                  {isAdding ? '...' : 'Save'}
                </Text>
              </TouchableOpacity>
            </View>

            <View style={styles.modalForm}>
              <Input
                label="Email *"
                placeholder="email@example.com"
                value={newContact.email}
                onChangeText={(text) => setNewContact(prev => ({ ...prev, email: text }))}
                autoCapitalize="none"
                keyboardType="email-address"
                editable={!isAdding}
              />
              <Input
                label="First Name"
                placeholder="John"
                value={newContact.first_name}
                onChangeText={(text) => setNewContact(prev => ({ ...prev, first_name: text }))}
                editable={!isAdding}
              />
              <Input
                label="Last Name"
                placeholder="Doe"
                value={newContact.last_name}
                onChangeText={(text) => setNewContact(prev => ({ ...prev, last_name: text }))}
                editable={!isAdding}
              />
              <Input
                label="Company"
                placeholder="Acme Inc"
                value={newContact.company}
                onChangeText={(text) => setNewContact(prev => ({ ...prev, company: text }))}
                editable={!isAdding}
              />
            </View>
          </KeyboardAvoidingView>
        </SafeAreaView>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background.primary,
  },
  searchContainer: {
    backgroundColor: colors.background.secondary,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[2],
    borderBottomWidth: 1,
    borderBottomColor: colors.border.primary,
  },
  searchInput: {
    backgroundColor: colors.zinc[100],
    borderRadius: borderRadius.md,
    paddingHorizontal: spacing[3.5],
    paddingVertical: spacing[2.5],
    fontSize: fontSize.base,
    color: colors.text.primary,
    fontFamily: 'Inter_400Regular',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  list: {
    paddingVertical: spacing[2],
  },
  listEmpty: {
    flex: 1,
  },
  contactItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.background.secondary,
    marginHorizontal: spacing[3],
    marginVertical: spacing[1],
    padding: spacing[3],
    borderRadius: borderRadius.xl,
    ...getShadow('sm'),
  },
  contactInfo: {
    flex: 1,
    marginLeft: spacing[3],
  },
  contactName: {
    fontSize: fontSize.base,
    fontWeight: fontWeight.semibold,
    color: colors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  contactEmail: {
    fontSize: fontSize.sm,
    color: colors.text.muted,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  contactCompany: {
    fontSize: fontSize.xs,
    color: colors.text.placeholder,
    marginTop: spacing[0.5],
    fontFamily: 'Inter_400Regular',
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: spacing[10],
  },
  emptyIcon: {
    fontSize: 48,
    marginBottom: spacing[4],
  },
  emptyText: {
    fontSize: fontSize.base,
    color: colors.text.muted,
    textAlign: 'center',
    marginBottom: spacing[4],
    fontFamily: 'Inter_400Regular',
  },
  emptyButton: {
    marginTop: spacing[2],
  },
  footer: {
    paddingVertical: spacing[5],
    alignItems: 'center',
  },
  modalContainer: {
    flex: 1,
    backgroundColor: colors.background.primary,
  },
  modalContent: {
    flex: 1,
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: colors.background.secondary,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    borderBottomWidth: 1,
    borderBottomColor: colors.border.primary,
  },
  modalCancel: {
    fontSize: fontSize.base,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  modalTitle: {
    fontSize: fontSize.lg,
    fontWeight: fontWeight.semibold,
    color: colors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  modalSave: {
    fontSize: fontSize.base,
    fontWeight: fontWeight.semibold,
    color: colors.brand.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  modalSaveDisabled: {
    opacity: 0.5,
  },
  modalForm: {
    padding: spacing[4],
  },
});
