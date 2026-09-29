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
import { PaymentBanner } from '../../src/components';
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
      <View style={styles.contactAvatar}>
        <Text style={styles.contactInitial}>
          {(item.first_name || item.email).charAt(0).toUpperCase()}
        </Text>
      </View>
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
      <View style={[styles.statusBadge, item.status === 'subscribed' ? styles.statusSubscribed : styles.statusUnsubscribed]}>
        <Text style={styles.statusText}>
          {item.status === 'subscribed' ? '✓' : '✗'}
        </Text>
      </View>
    </View>
  );

  const renderEmpty = () => (
    <View style={styles.emptyContainer}>
      <Text style={styles.emptyIcon}>👥</Text>
      <Text style={styles.emptyText}>
        {search ? 'No contacts found' : 'No contacts yet'}
      </Text>
      {!search && (
        <TouchableOpacity style={styles.emptyButton} onPress={() => setShowAddModal(true)}>
          <Text style={styles.emptyButtonText}>Add your first contact</Text>
        </TouchableOpacity>
      )}
    </View>
  );

  const renderFooter = () => {
    if (!isLoadingMore) return null;
    return (
      <View style={styles.footer}>
        <ActivityIndicator size="small" color="#3B82F6" />
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
      <View style={styles.header}>
        <Text style={styles.title}>Contacts</Text>
        <TouchableOpacity style={styles.addButton} onPress={() => setShowAddModal(true)}>
          <Text style={styles.addButtonText}>+ Add</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.searchContainer}>
        <TextInput
          style={styles.searchInput}
          placeholder="Search contacts..."
          placeholderTextColor="#9CA3AF"
          value={search}
          onChangeText={setSearch}
          autoCapitalize="none"
        />
      </View>

      {isLoading ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color="#3B82F6" />
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
            <RefreshControl refreshing={isRefreshing} onRefresh={handleRefresh} />
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
              <View style={styles.modalField}>
                <Text style={styles.modalLabel}>Email *</Text>
                <TextInput
                  style={styles.modalInput}
                  placeholder="email@example.com"
                  placeholderTextColor="#9CA3AF"
                  value={newContact.email}
                  onChangeText={(text) => setNewContact(prev => ({ ...prev, email: text }))}
                  autoCapitalize="none"
                  keyboardType="email-address"
                  editable={!isAdding}
                />
              </View>

              <View style={styles.modalField}>
                <Text style={styles.modalLabel}>First Name</Text>
                <TextInput
                  style={styles.modalInput}
                  placeholder="John"
                  placeholderTextColor="#9CA3AF"
                  value={newContact.first_name}
                  onChangeText={(text) => setNewContact(prev => ({ ...prev, first_name: text }))}
                  editable={!isAdding}
                />
              </View>

              <View style={styles.modalField}>
                <Text style={styles.modalLabel}>Last Name</Text>
                <TextInput
                  style={styles.modalInput}
                  placeholder="Doe"
                  placeholderTextColor="#9CA3AF"
                  value={newContact.last_name}
                  onChangeText={(text) => setNewContact(prev => ({ ...prev, last_name: text }))}
                  editable={!isAdding}
                />
              </View>

              <View style={styles.modalField}>
                <Text style={styles.modalLabel}>Company</Text>
                <TextInput
                  style={styles.modalInput}
                  placeholder="Acme Inc"
                  placeholderTextColor="#9CA3AF"
                  value={newContact.company}
                  onChangeText={(text) => setNewContact(prev => ({ ...prev, company: text }))}
                  editable={!isAdding}
                />
              </View>
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
    backgroundColor: '#F9FAFB',
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  title: {
    fontSize: 20,
    fontWeight: '700',
    color: '#111827',
  },
  addButton: {
    backgroundColor: '#3B82F6',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 8,
  },
  addButtonText: {
    fontSize: 14,
    fontWeight: '600',
    color: '#FFFFFF',
  },
  searchContainer: {
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  searchInput: {
    backgroundColor: '#F3F4F6',
    borderRadius: 8,
    paddingHorizontal: 14,
    paddingVertical: 10,
    fontSize: 15,
    color: '#111827',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  list: {
    paddingVertical: 8,
  },
  listEmpty: {
    flex: 1,
  },
  contactItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    marginHorizontal: 12,
    marginVertical: 4,
    padding: 12,
    borderRadius: 12,
  },
  contactAvatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#3B82F6',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  contactInitial: {
    fontSize: 18,
    fontWeight: '600',
    color: '#FFFFFF',
  },
  contactInfo: {
    flex: 1,
  },
  contactName: {
    fontSize: 15,
    fontWeight: '600',
    color: '#111827',
  },
  contactEmail: {
    fontSize: 13,
    color: '#6B7280',
    marginTop: 2,
  },
  contactCompany: {
    fontSize: 12,
    color: '#9CA3AF',
    marginTop: 2,
  },
  statusBadge: {
    width: 24,
    height: 24,
    borderRadius: 12,
    justifyContent: 'center',
    alignItems: 'center',
  },
  statusSubscribed: {
    backgroundColor: '#D1FAE5',
  },
  statusUnsubscribed: {
    backgroundColor: '#FEE2E2',
  },
  statusText: {
    fontSize: 12,
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  emptyIcon: {
    fontSize: 48,
    marginBottom: 16,
  },
  emptyText: {
    fontSize: 16,
    color: '#6B7280',
    textAlign: 'center',
    marginBottom: 16,
  },
  emptyButton: {
    backgroundColor: '#3B82F6',
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: 8,
  },
  emptyButtonText: {
    fontSize: 14,
    fontWeight: '600',
    color: '#FFFFFF',
  },
  footer: {
    paddingVertical: 20,
    alignItems: 'center',
  },
  modalContainer: {
    flex: 1,
    backgroundColor: '#F9FAFB',
  },
  modalContent: {
    flex: 1,
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#E5E7EB',
  },
  modalCancel: {
    fontSize: 16,
    color: '#6B7280',
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: '600',
    color: '#111827',
  },
  modalSave: {
    fontSize: 16,
    fontWeight: '600',
    color: '#3B82F6',
  },
  modalSaveDisabled: {
    color: '#93C5FD',
  },
  modalForm: {
    padding: 16,
  },
  modalField: {
    marginBottom: 16,
  },
  modalLabel: {
    fontSize: 14,
    fontWeight: '500',
    color: '#374151',
    marginBottom: 6,
  },
  modalInput: {
    backgroundColor: '#FFFFFF',
    borderWidth: 1,
    borderColor: '#E5E7EB',
    borderRadius: 8,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    color: '#111827',
  },
});
