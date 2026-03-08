import { useState, useEffect } from 'react';
import {
    Box,
    Title,
    Text,
    Group,
    Button,
    Modal,
    TextInput,
    Select,
    Stack,
    Badge,
    Loader,
    Alert,
    ActionIcon,
    Table,
    useMantineTheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { DragDropContext, Droppable, Draggable } from '@hello-pangea/dnd';
import {
    IconPlus,
    IconTrash,
    IconEdit,
    IconShield,
    IconShieldCheck,
    IconGripVertical,
    IconRefresh,
} from '@tabler/icons-react';

export function FirewallAppContent() {
    const theme = useMantineTheme();
    const [status, setStatus] = useState({ active: false, status: 'inactive' });
    const [rules, setRules] = useState([]);
    const [defaultPolicies, setDefaultPolicies] = useState({
        incoming: 'allow',
        outgoing: 'allow',
        routed: 'allow',
    });
    const [policyEditMode, setPolicyEditMode] = useState(false);
    const [editingPolicies, setEditingPolicies] = useState({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [opened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingRule, setEditingRule] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [policySubmitting, setPolicySubmitting] = useState(false);

    const [formData, setFormData] = useState({
        action: 'allow',
        port: '',
        protocol: 'TCP',
        from: '',
        to: '',
        interface: '',
        comment: '',
        direction: 'IN',
    });

    const [modalError, setModalError] = useState(null);

    useEffect(() => {
        fetchStatus();
        fetchRules();
        fetchDefaultPolicies();
    }, []);

    const fetchStatus = async () => {
        try {
            const response = await fetch('/api/firewall/status');
            const data = await response.json();
            setStatus(data);
        } catch (err) {
            console.error('Failed to fetch firewall status:', err);
        }
    };

    const fetchDefaultPolicies = async () => {
        try {
            const response = await fetch('/api/firewall/default-policies');
            const data = await response.json();
            setDefaultPolicies(data);
        } catch (err) {
            console.error('Failed to fetch default policies:', err);
        }
    };

    const fetchRules = async () => {
        try {
            setLoading(true);
            const response = await fetch('/api/firewall/rules');
            const data = await response.json();
            setRules(data.rules || []);
            setError(null);
        } catch (err) {
            setError('Failed to load firewall rules');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleToggleFirewall = async () => {
        setToggling(true);
        setError(null);

        try {
            const endpoint = status.active ? '/api/firewall/disable' : '/api/firewall/enable';
            const response = await fetch(endpoint, { method: 'POST' });
            const data = await response.json();

            if (response.ok) {
                setStatus({
                    active: data.active,
                    status: data.status,
                });
                await fetchRules();
            } else {
                setError(data.message || 'Failed to toggle firewall');
            }
        } catch (err) {
            setError('Failed to toggle firewall');
            console.error(err);
        } finally {
            setToggling(false);
        }
    };

    const handleRefresh = async () => {
        setRefreshing(true);
        try {
            await fetchStatus();
            await fetchRules();
            await fetchDefaultPolicies();
        } finally {
            setRefreshing(false);
        }
    };

    const handlePolicyChange = async (direction, policy) => {
        setPolicySubmitting(true);
        setError(null);

        try {
            const response = await fetch('/api/firewall/default-policies', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ direction, policy }),
            });

            const data = await response.json();

            if (response.ok) {
                setDefaultPolicies(data.policies);
            } else {
                setError(data.message || 'Failed to update policy');
            }
        } catch (err) {
            setError('Failed to update policy');
            console.error(err);
        } finally {
            setPolicySubmitting(false);
        }
    };

    const startPolicyEdit = () => {
        setEditingPolicies({ ...defaultPolicies });
        setPolicyEditMode(true);
    };

    const cancelPolicyEdit = () => {
        setEditingPolicies({});
        setPolicyEditMode(false);
    };

    const savePolicyChanges = async () => {
        setPolicySubmitting(true);
        setError(null);

        try {
            // Update each policy that changed
            for (const direction of ['incoming', 'outgoing', 'routed']) {
                if (editingPolicies[direction] !== defaultPolicies[direction]) {
                    const response = await fetch('/api/firewall/default-policies', {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ direction, policy: editingPolicies[direction] }),
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message || 'Failed to update policy');
                    }
                }
            }

            // Refresh all policies after saving
            await fetchDefaultPolicies();
            setPolicyEditMode(false);
            setEditingPolicies({});
        } catch (err) {
            setError(err.message || 'Failed to save policies');
            console.error(err);
        } finally {
            setPolicySubmitting(false);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setModalError(null);
        setSubmitting(true);

        try {
            const submitData = {
                ...formData,
                port: formData.port || null,
                from: formData.from || 'any',
                to: formData.to || 'any',
            };

            let response;

            // If editing, use PUT to update the rule (preserves position)
            if (editingRule) {
                response = await fetch(`/api/firewall/rules/${editingRule.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(submitData),
                });
            } else {
                response = await fetch('/api/firewall/rules', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(submitData),
                });
            }

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to save rule');
            }

            await fetchRules();
            closeModal();
            resetForm();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setSubmitting(false);
        }
    };

    const handleDelete = async (id) => {
        try {
            const response = await fetch(`/api/firewall/rules/${id}`, {
                method: 'DELETE',
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Delete failed');
            }

            await fetchRules();
            setDeleteConfirm(null);
        } catch (err) {
            setError(err.message);
        }
    };

    const handleReorder = async (fromPriority, toPriority) => {
        setLoading(true);
        try {
            const response = await fetch('/api/firewall/rules/reorder', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    from_priority: fromPriority,
                    to_priority: toPriority,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Reorder failed');
            }

            // Add a small delay to allow UFW to process the changes
            await new Promise(resolve => setTimeout(resolve, 500));
            await fetchRules();
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    const openEditModal = (rule) => {
        setEditingRule(rule);
        setModalError(null);
        setFormData({
            action: rule.action.toLowerCase(),
            port: rule.port || '',
            protocol: (rule.protocol?.toLowerCase() === 'any' ? 'any' : rule.protocol?.toUpperCase()) || 'TCP',
            from: rule.from === 'any' ? '' : rule.from,
            to: rule.to === 'any' ? '' : rule.to,
            interface: rule.interface || '',
            comment: rule.comment || '',
            direction: rule.direction || 'IN',
        });
        openModal();
    };

    const resetForm = () => {
        setEditingRule(null);
        setModalError(null);
        setFormData({
            action: 'allow',
            port: '',
            protocol: 'TCP',
            from: '',
            to: '',
            interface: '',
            comment: '',
            direction: 'IN',
        });
    };

    const openCreateModal = () => {
        resetForm();
        openModal();
    };

    const handleDragEnd = (result) => {
        if (!result.destination) {
            return;
        }

        const sourceIndex = result.source.index;
        const destIndex = result.destination.index;

        if (sourceIndex !== destIndex) {
            const sourcePriority = rules[sourceIndex].priority;
            const destPriority = rules[destIndex].priority;
            handleReorder(sourcePriority, destPriority);
        }
    };

    const actionOptions = [
        { value: 'allow', label: 'Allow' },
        { value: 'deny', label: 'Deny' },
        { value: 'reject', label: 'Reject' },
        { value: 'drop', label: 'Drop' },
    ];

    const protocolOptions = [
        { value: 'TCP', label: 'TCP' },
        { value: 'UDP', label: 'UDP' },
        { value: 'any', label: 'Any' },
    ];

    const directionOptions = [
        { value: 'IN', label: 'Incoming' },
        { value: 'OUT', label: 'Outgoing' },
    ];

    if (loading) {
        return (
            <Box style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                <Loader size="lg" />
            </Box>
        );
    }

    return (
        <Box style={{ padding: '24px', height: '100%', overflow: 'auto' }}>
            <Group justify="space-between" mb="lg">
                <div>
                    <Title order={3} c="white">Firewall</Title>
                    <Text size="sm" c="dimmed">Manage UFW firewall rules</Text>
                </div>
                <Group gap="sm">
                    <Button
                        variant="light"
                        color="blue"
                        leftSection={<IconRefresh size={18} />}
                        onClick={handleRefresh}
                        loading={refreshing}
                    >
                        Refresh
                    </Button>
                    <Button
                        variant={status.active ? 'filled' : 'light'}
                        color={status.active ? 'red' : 'green'}
                        leftSection={status.active ? <IconShieldCheck size={18} /> : <IconShield size={18} />}
                        onClick={handleToggleFirewall}
                        loading={toggling}
                    >
                        {status.active ? 'Disable Firewall' : 'Enable Firewall'}
                    </Button>
                    <Button
                        leftSection={<IconPlus size={16} />}
                        onClick={openCreateModal}
                    >
                        Add Rule
                    </Button>
                </Group>
            </Group>

            {error && (
                <Alert
                    color="red"
                    variant="light"
                    mb="md"
                    onClose={() => setError(null)}
                    withCloseButton
                >
                    {error}
                </Alert>
            )}

            {!status.active && (
                <Alert
                    color="yellow"
                    variant="light"
                    mb="md"
                    icon={<IconShield size={16} />}
                >
                    Firewall is disabled. Enable it to manage rules.
                </Alert>
            )}

            {/* Default Policies Section */}
            <Box
                mb="lg"
                p="md"
                style={{
                    backgroundColor: theme.colors.dark[6],
                    borderRadius: '12px',
                    border: `1px solid ${theme.colors.dark[4]}`,
                }}
            >
                <Group justify="space-between" align="flex-start" mb="md">
                    <div>
                        <Title order={4}>Default Policies</Title>
                        <Text size="sm" c="dimmed">
                            Set the default action for incoming, outgoing, and routed traffic.
                        </Text>
                    </div>
                    {!policyEditMode && (
                        <Button
                            variant="light"
                            size="sm"
                            leftSection={<IconEdit size={16} />}
                            onClick={startPolicyEdit}
                        >
                            Edit
                        </Button>
                    )}
                </Group>

                <Stack gap="md">
                    {/* Incoming */}
                    <Group justify="space-between" align="center">
                        <div>
                            <Text fw={500}>Incoming</Text>
                            <Text size="xs" c="dimmed">Default policy for incoming connections</Text>
                        </div>
                        {policyEditMode ? (
                            <Select
                                value={editingPolicies.incoming}
                                onChange={(value) => setEditingPolicies({ ...editingPolicies, incoming: value })}
                                data={[
                                    { value: 'allow', label: 'Allow' },
                                    { value: 'deny', label: 'Deny' },
                                    { value: 'reject', label: 'Reject' },
                                ]}
                                w={130}
                            />
                        ) : (
                            <Badge
                                color={defaultPolicies.incoming === 'allow' ? 'green' : defaultPolicies.incoming === 'deny' ? 'yellow' : 'red'}
                                variant="light"
                                size="lg"
                            >
                                {defaultPolicies.incoming.charAt(0).toUpperCase() + defaultPolicies.incoming.slice(1)}
                            </Badge>
                        )}
                    </Group>

                    {/* Outgoing */}
                    <Group justify="space-between" align="center">
                        <div>
                            <Text fw={500}>Outgoing</Text>
                            <Text size="xs" c="dimmed">Default policy for outgoing connections</Text>
                        </div>
                        {policyEditMode ? (
                            <Select
                                value={editingPolicies.outgoing}
                                onChange={(value) => setEditingPolicies({ ...editingPolicies, outgoing: value })}
                                data={[
                                    { value: 'allow', label: 'Allow' },
                                    { value: 'deny', label: 'Deny' },
                                    { value: 'reject', label: 'Reject' },
                                ]}
                                w={130}
                            />
                        ) : (
                            <Badge
                                color={defaultPolicies.outgoing === 'allow' ? 'green' : defaultPolicies.outgoing === 'deny' ? 'yellow' : 'red'}
                                variant="light"
                                size="lg"
                            >
                                {defaultPolicies.outgoing.charAt(0).toUpperCase() + defaultPolicies.outgoing.slice(1)}
                            </Badge>
                        )}
                    </Group>

                    {/* Routed */}
                    <Group justify="space-between" align="center">
                        <div>
                            <Text fw={500}>Routed</Text>
                            <Text size="xs" c="dimmed">Default policy for routed traffic (forwarded packets)</Text>
                        </div>
                        {policyEditMode ? (
                            <Select
                                value={editingPolicies.routed}
                                onChange={(value) => setEditingPolicies({ ...editingPolicies, routed: value })}
                                data={[
                                    { value: 'allow', label: 'Allow' },
                                    { value: 'deny', label: 'Deny' },
                                    { value: 'reject', label: 'Reject' },
                                ]}
                                w={130}
                            />
                        ) : (
                            <Badge
                                color={defaultPolicies.routed === 'allow' ? 'green' : defaultPolicies.routed === 'deny' ? 'yellow' : 'red'}
                                variant="light"
                                size="lg"
                            >
                                {defaultPolicies.routed.charAt(0).toUpperCase() + defaultPolicies.routed.slice(1)}
                            </Badge>
                        )}
                    </Group>

                    {/* Save/Cancel buttons in edit mode */}
                    {policyEditMode && (
                        <Group justify="flex-end" mt="md">
                            <Button
                                variant="default"
                                onClick={cancelPolicyEdit}
                                disabled={policySubmitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                color="blue"
                                onClick={savePolicyChanges}
                                loading={policySubmitting}
                            >
                                Save Changes
                            </Button>
                        </Group>
                    )}
                </Stack>
            </Box>

            <Title order={4} mb="md">Firewall Rules</Title>

            {rules.length === 0 ? (
                <Box
                    style={{
                        backgroundColor: theme.colors.dark[6],
                        borderRadius: '12px',
                        padding: '40px',
                        textAlign: 'center',
                        border: `1px solid ${theme.colors.dark[4]}`,
                    }}
                >
                    <Group justify="center" mb="md">
                        <IconShield size={48} color="gray" />
                    </Group>
                    <Text c="dimmed" size="lg" mb="md">No firewall rules</Text>
                    <Text c="dimmed" size="sm" mb="lg">
                        {status.active
                            ? 'Add a rule to control network traffic'
                            : 'Enable the firewall to start adding rules'}
                    </Text>
                    <Button leftSection={<IconPlus size={16} />} onClick={openCreateModal}>
                        Add Your First Rule
                    </Button>
                </Box>
            ) : (
                <DragDropContext onDragEnd={handleDragEnd}>
                    <Droppable droppableId="firewall-rules">
                        {(provided) => (
                            <Box style={{ position: 'relative' }}>
                                <Table
                                {...provided.droppableProps}
                                ref={provided.innerRef}
                                striped
                                highlightOnHover
                                withTableBorder
                                style={{
                                    backgroundColor: theme.colors.dark[6],
                                    borderRadius: '12px',
                                    overflow: 'hidden',
                                }}
                            >
                                <Table.Thead style={{ backgroundColor: theme.colors.dark[5] }}>
                                    <Table.Tr>
                                        <Table.Th c="dimmed" style={{ width: 40 }}></Table.Th>
                                        <Table.Th c="dimmed">Priority</Table.Th>
                                        <Table.Th c="dimmed">Action</Table.Th>
                                        <Table.Th c="dimmed">Direction</Table.Th>
                                        <Table.Th c="dimmed">Port</Table.Th>
                                        <Table.Th c="dimmed">Protocol</Table.Th>
                                        <Table.Th c="dimmed">From</Table.Th>
                                        <Table.Th c="dimmed">To</Table.Th>
                                        <Table.Th c="dimmed">Interface</Table.Th>
                                        <Table.Th c="dimmed">Actions</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {rules.map((rule, index) => (
                                        <Draggable key={rule.id} draggableId={String(rule.id)} index={index}>
                                            {(provided, snapshot) => (
                                                <Table.Tr
                                                    ref={provided.innerRef}
                                                    {...provided.draggableProps}
                                                    style={{
                                                        ...provided.draggableProps.style,
                                                        backgroundColor: snapshot.isDragging ? theme.colors.dark[5] : undefined,
                                                    }}
                                                >
                                                    <Table.Td {...provided.dragHandleProps}>
                                                        <IconGripVertical size={16} color="gray" />
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.priority}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Badge
                                                            color={
                                                                rule.action === 'ALLOW' ? 'green' :
                                                                rule.action === 'DENY' ? 'yellow' :
                                                                rule.action === 'REJECT' ? 'orange' : 'red'
                                                            }
                                                            variant="light"
                                                        >
                                                            {rule.action}
                                                        </Badge>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Badge color="blue" variant="light">
                                                            {rule.direction || 'IN'}
                                                        </Badge>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.port || 'any'}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.protocol?.toLowerCase() === 'any' ? 'any' : rule.protocol?.toUpperCase() || 'any'}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.from || 'any'}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.to || 'any'}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Text c="white">{rule.interface || '-'}</Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Group gap="xs">
                                                            <ActionIcon
                                                                variant="subtle"
                                                                color="gray"
                                                                onClick={() => openEditModal(rule)}
                                                            >
                                                                <IconEdit size={16} />
                                                            </ActionIcon>
                                                            <ActionIcon
                                                                variant="subtle"
                                                                color="red"
                                                                onClick={() => setDeleteConfirm(rule.id)}
                                                            >
                                                                <IconTrash size={16} />
                                                            </ActionIcon>
                                                        </Group>
                                                    </Table.Td>
                                                </Table.Tr>
                                            )}
                                        </Draggable>
                                    ))}
                                    {provided.placeholder}
                                </Table.Tbody>
                            </Table>
                            </Box>
                        )}
                    </Droppable>
                </DragDropContext>
            )}

            <Modal
                opened={opened}
                onClose={closeModal}
                title={<Text fw={600}>{editingRule ? 'Edit Rule' : 'Add Rule'}</Text>}
                size="md"
                centered
            >
                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(e); }}>
                    <Stack gap="md">
                        {modalError && (
                            <Alert color="red" variant="light">
                                {modalError}
                            </Alert>
                        )}

                        <Select
                            label="Action"
                            value={formData.action}
                            onChange={(value) => setFormData({ ...formData, action: value })}
                            data={actionOptions}
                            required
                        />

                        <Select
                            label="Direction"
                            value={formData.direction}
                            onChange={(value) => setFormData({ ...formData, direction: value })}
                            data={directionOptions}
                            required
                        />

                        <Group grow>
                            <TextInput
                                label="Port"
                                placeholder="80 or 80:90"
                                value={formData.port}
                                onChange={(e) => setFormData({ ...formData, port: e.target.value })}
                            />

                            <Select
                                label="Protocol"
                                value={formData.protocol}
                                onChange={(value) => setFormData({ ...formData, protocol: value })}
                                data={protocolOptions}
                                required
                            />
                        </Group>

                        <Group grow>
                            <TextInput
                                label="From"
                                placeholder="any or IP address"
                                value={formData.from}
                                onChange={(e) => setFormData({ ...formData, from: e.target.value })}
                            />

                            <TextInput
                                label="To"
                                placeholder="any or IP address"
                                value={formData.to}
                                onChange={(e) => setFormData({ ...formData, to: e.target.value })}
                            />
                        </Group>

                        <TextInput
                            label="Interface"
                            placeholder="e.g., eth0, enp0s3"
                            value={formData.interface}
                            onChange={(e) => setFormData({ ...formData, interface: e.target.value })}
                        />

                        <TextInput
                            label="Comment"
                            placeholder="Optional description"
                            value={formData.comment}
                            onChange={(e) => setFormData({ ...formData, comment: e.target.value })}
                        />

                        <Group justify="flex-end" mt="md">
                            <Button variant="subtle" onClick={closeModal}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={submitting}>
                                {editingRule ? 'Save Changes' : 'Create'}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>

            {/* Delete Confirmation Modal */}
            <Modal
                opened={!!deleteConfirm}
                onClose={() => setDeleteConfirm(null)}
                title={<Text fw={600}>Delete Rule</Text>}
                size="sm"
                centered
            >
                <Text c="dimmed" mb="lg">
                    Are you sure you want to delete this firewall rule? This action cannot be undone.
                </Text>
                <Group justify="flex-end">
                    <Button variant="subtle" onClick={() => setDeleteConfirm(null)}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={() => handleDelete(deleteConfirm)}>
                        Delete
                    </Button>
                </Group>
            </Modal>
        </Box>
    );
}
