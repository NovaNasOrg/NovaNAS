import { useEffect, useState } from 'react';
import { Box, Text, Badge, Group, LoadingOverlay, ThemeIcon, Table, Button, Modal, ScrollArea, Stack, Divider, Loader, Popover } from '@mantine/core';
import { IconRefresh, IconDisc, IconHeart, IconAlertTriangle, IconInfoCircle, IconPlayerPlay, IconSearch } from '@tabler/icons-react';
import { useMantineTheme } from '@mantine/core';

function AttributeCell({ attr }) {
    const [hovered, setHovered] = useState(false);
    const displayName = attr.name.replace(/_/g, ' ');

    return (
        <Popover
            position="top"
            withArrow
            shadow="md"
            opened={hovered}
            onChange={setHovered}
        >
            <Popover.Target>
                <Text
                    size="sm"
                    fw={500}
                    lineClamp={1}
                    style={{ cursor: 'help', maxWidth: '200px' }}
                    onMouseEnter={() => setHovered(true)}
                    onMouseLeave={() => setHovered(false)}
                >
                    {displayName}
                </Text>
            </Popover.Target>
            <Popover.Dropdown>
                <Text size="xs">{displayName}</Text>
            </Popover.Dropdown>
        </Popover>
    );
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Key SMART attributes to display
const KEY_ATTRIBUTES = [
    'Reallocated_Sector_Ct',
    'Power_On_Hours',
    'Power_Cycle_Count',
    'Wear_Leveling_Count',
    'Used_Rsvd_Blk_Cnt_Tot',
    'Program_Fail_Cnt_Total',
    'Erase_Fail_Count_Total',
    'Runtime_Bad_Block',
    'Uncorrectable_Error_Cnt',
    'CRC_Error_Count',
    'POR_Recovery_Count',
    'Total_LBAs_Written',
    'Total_LBAs_Read',
];

function DiskHealthCard({ disk, onViewTests, onRunTest, isRunningTest }) {
    const theme = useMantineTheme();
    const health = disk.health;
    const isHealthy = health?.passed ?? false;
    const hasSmartSupport = disk.capabilities?.smart_supported ?? false;

    return (
        <Box
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: '20px',
                backgroundColor: theme.colors.dark[6],
                borderRadius: '12px',
                padding: '16px 20px',
                border: `1px solid ${theme.colors.dark[4]}`,
                transition: 'transform 0.2s ease, box-shadow 0.2s ease',
                marginBottom: '12px',
            }}
        >
            {/* Icon */}
            <ThemeIcon
                size={56}
                radius="md"
                variant="light"
                color={isHealthy ? 'green' : 'red'}
            >
                <IconHeart size={28} />
            </ThemeIcon>

            {/* Device Info */}
            <Box style={{ flex: '0 0 120px' }}>
                <Text size="xl" fw={700}>/dev/{disk.name}</Text>
                <Text size="xs" c="dimmed">{disk.model || 'Unknown'}</Text>
            </Box>

            {/* Status */}
            <Box style={{ flex: '0 0 140px' }}>
                {hasSmartSupport ? (
                    <Group gap="xs">
                        <Badge
                            size="lg"
                            color={isHealthy ? 'green' : 'red'}
                            variant="light"
                            leftSection={isHealthy ? <IconHeart size={14} /> : <IconAlertTriangle size={14} />}
                        >
                            {isHealthy ? 'Healthy' : 'Failed'}
                        </Badge>
                    </Group>
                ) : (
                    <Badge size="lg" color="gray" variant="light">
                        Not Supported
                    </Badge>
                )}
                <Text size="xs" c="dimmed" mt={4}>SMART Status</Text>
            </Box>

            {/* Last Test */}
            <Box style={{ flex: '0 0 120px' }}>
                <Text size="sm" fw={500}>
                    {disk.is_test_running ? 'Running...' : (disk.last_test?.timestamp_human || '-')}
                </Text>
                <Text size="xs" c="dimmed" mt={4}>Last Test</Text>
            </Box>

            {/* Test Results Button */}
            <Box style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '4px' }}>
                {hasSmartSupport && (
                    <>
                        <Button
                            variant="light"
                            color="blue"
                            size="sm"
                            leftSection={<IconInfoCircle size={16} />}
                            onClick={() => onViewTests(disk)}
                            style={{ width: '100%' }}
                        >
                            View Details
                        </Button>
                        <Button
                            variant="light"
                            color="green"
                            size="sm"
                            leftSection={isRunningTest || disk.is_test_running ? <Loader size={14} color="green" /> : <IconPlayerPlay size={16} />}
                            onClick={() => onRunTest(disk)}
                            disabled={isRunningTest || disk.is_test_running}
                            style={{ width: '100%' }}
                        >
                            {isRunningTest || disk.is_test_running ? 'Running...' : 'Run Test'}
                        </Button>
                    </>
                )}
            </Box>
        </Box>
    );
}

export function HealthTab() {
    const [disks, setDisks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedDisk, setSelectedDisk] = useState(null);
    const [detailedInfo, setDetailedInfo] = useState(null);
    const [testLoading, setTestLoading] = useState(false);
    const [testModalOpen, setTestModalOpen] = useState(false);
    const [runningTests, setRunningTests] = useState({}); // Track running tests per disk
    const [scanningAll, setScanningAll] = useState(false);
    const theme = useMantineTheme();

    const fetchHealth = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch('/api/storage/smart/health', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            setDisks(data.disks || []);
        } catch (err) {
            setError('Failed to load SMART health data');
            console.error('Error fetching SMART health:', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchDiskHealth = async (deviceName) => {
        try {
            const response = await fetch('/api/storage/smart/health', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            // Update only the specific disk in state
            setDisks(prevDisks => {
                return prevDisks.map(disk => {
                    const updatedDisk = data.disks?.find(d => d.name === disk.name);
                    return updatedDisk || disk;
                });
            });
        } catch (err) {
            console.error('Error fetching disk health:', err);
        }
    };

    const fetchDetailedInfo = async (device) => {
        setTestLoading(true);
        try {
            const response = await fetch(`/api/storage/smart/${device}/info`, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            setDetailedInfo(data.info || null);
        } catch (err) {
            console.error('Error fetching detailed info:', err);
            setDetailedInfo(null);
        } finally {
            setTestLoading(false);
        }
    };

    const handleViewTests = (disk) => {
        setSelectedDisk(disk);
        setDetailedInfo(null); // Clear previous data
        setTestLoading(true); // Show loading immediately
        setTestModalOpen(true);
        // Small delay to ensure modal is open before fetching
        setTimeout(() => {
            fetchDetailedInfo(disk.name);
        }, 100);
    };

    const handleRunTest = async (disk) => {
        // Immediately show loading state for this disk
        setRunningTests(prev => ({ ...prev, [disk.name]: true }));

        try {
            const response = await fetch(`/api/storage/smart/${disk.name}/test`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
            });
            const data = await response.json();
            if (data.error) {
                console.error('Error running test:', data.error);
                // Reset on error
                setRunningTests(prev => ({ ...prev, [disk.name]: false }));
            } else {
                // Refresh only this disk after a short delay to get updated test status
                setTimeout(() => fetchDiskHealth(disk.name), 500);
            }
        } catch (err) {
            console.error('Error running SMART test:', err);
            setRunningTests(prev => ({ ...prev, [disk.name]: false }));
        }
    };

    const handleScanAll = async () => {
        setScanningAll(true);
        try {
            // Filter out disks that are already running tests
            const disksToScan = disks.filter(d => !d.is_test_running && d.capabilities?.smart_supported);

            // Start tests sequentially for disks that aren't already running
            for (const disk of disksToScan) {
                try {
                    await fetch(`/api/storage/smart/${disk.name}/test`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        },
                    });
                } catch (err) {
                    console.error(`Error starting test on ${disk.name}:`, err);
                }
            }
        } catch (err) {
            console.error('Error scanning all disks:', err);
        } finally {
            setScanningAll(false);
            // Refresh only the disks that were scanned (without full page reload)
            setTimeout(() => {
                const disksToUpdate = disks.filter(d => d.capabilities?.smart_supported);
                disksToUpdate.forEach(disk => fetchDiskHealth(disk.name));
            }, 1000);
        }
    };

    useEffect(() => {
        fetchHealth();
    }, []);

    // Calculate stats
    const healthyCount = disks.filter(d => d.health?.passed).length;
    const failedCount = disks.filter(d => d.health && !d.health.passed).length;
    const notSupportedCount = disks.filter(d => !d.capabilities?.smart_supported).length;

    // Calculate next test time (earliest across all disks with SMART support)
    const nextTestInfo = (() => {
        const disksWithNextTest = disks
            .filter(d => d.next_test?.hours_until !== undefined)
            .sort((a, b) => a.next_test.hours_until - b.next_test.hours_until);

        if (disksWithNextTest.length === 0) {
            return { days: 7, hours: 7 * 24, text: 'in 7 days' };
        }

        const earliest = disksWithNextTest[0].next_test;
        if (earliest.hours_until < 24) {
            return { days: 0, hours: earliest.hours_until, text: `in ${Math.round(earliest.hours_until)} hours` };
        }
        return { days: earliest.days_until, hours: earliest.hours_until, text: `in ${Math.round(earliest.days_until)} days` };
    })();

    // Filter attributes to show only key ones
    const filteredAttributes = detailedInfo?.attributes?.filter(attr =>
        KEY_ATTRIBUTES.includes(attr.name)
    ) || [];

    return (
        <Box style={{ position: 'relative' }}>
            <LoadingOverlay visible={loading} zIndex={1000} overlayProps={{ radius: 'sm', blur: 2 }} />

            {/* Summary Stats */}
            <Box
                style={{
                    backgroundColor: theme.colors.dark[6],
                    borderRadius: '12px',
                    padding: '16px 20px',
                    marginBottom: '24px',
                    border: `1px solid ${theme.colors.dark[4]}`,
                }}
            >
                <Group justify="space-between" align="center">
                    <Group gap="xl">
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Healthy</Text>
                            <Badge size="lg" color="green" variant="light">{healthyCount}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Failed</Text>
                            <Badge size="lg" color="red" variant="light">{failedCount}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Not Supported</Text>
                            <Badge size="lg" color="gray" variant="light">{notSupportedCount}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Total Disks</Text>
                            <Badge size="lg" color="gray" variant="light">{disks.length}</Badge>
                        </Box>
                        <Box>
                            <Text size="xs" c="dimmed" mb={4}>Next SMART Test</Text>
                            <Badge size="lg" color="blue" variant="light">{nextTestInfo.text}</Badge>
                        </Box>
                    </Group>
                    <Box
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px',
                        }}
                    >
                        <Box
                            onClick={fetchHealth}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: '6px',
                                padding: '8px 16px',
                                borderRadius: '8px',
                                cursor: 'pointer',
                                backgroundColor: theme.colors.blue[6],
                                color: 'white',
                                transition: 'background-color 0.15s ease',
                            }}
                        >
                            <IconRefresh size={16} />
                            <Text size="sm" fw={500}>Refresh</Text>
                        </Box>
                        <Box
                            onClick={handleScanAll}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: '6px',
                                padding: '8px 16px',
                                borderRadius: '8px',
                                cursor: scanningAll ? 'not-allowed' : 'pointer',
                                backgroundColor: scanningAll ? theme.colors.gray[6] : theme.colors.green[6],
                                color: 'white',
                                transition: 'background-color 0.15s ease',
                            }}
                        >
                            {scanningAll ? <Loader size={14} color="white" /> : <IconSearch size={16} />}
                            <Text size="sm" fw={500}>{scanningAll ? 'Scanning...' : 'Scan All'}</Text>
                        </Box>
                    </Box>
                </Group>
            </Box>

            {/* Disk List */}
            <Text size="lg" fw={600} mb="md">Disk Health</Text>

            {error && (
                <Text c="red" mb="md">{error}</Text>
            )}

            {!loading && !error && disks.length === 0 && (
                <Text c="dimmed">No disks found on this system.</Text>
            )}

            {!loading && !error && disks.length > 0 && (
                <Box>
                    {disks.map((disk) => (
                        <DiskHealthCard
                            key={disk.name}
                            disk={disk}
                            onViewTests={handleViewTests}
                            onRunTest={handleRunTest}
                            isRunningTest={runningTests[disk.name] || false}
                        />
                    ))}
                </Box>
            )}

            {/* Detailed Info Modal */}
            <Modal
                opened={testModalOpen}
                onClose={() => {
                    setTestModalOpen(false);
                    setDetailedInfo(null);
                }}
                title={selectedDisk ? `SMART Details - /dev/${selectedDisk.name}` : 'SMART Details'}
                size="xl"
            >
                {testLoading ? (
                    <Box style={{ minHeight: '300px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '12px' }}>
                        <Loader size="sm" color="blue" />
                        <Text size="sm" c="dimmed">Loading SMART details...</Text>
                    </Box>
                ) : detailedInfo ? (
                    <ScrollArea>
                        <Stack gap="md">
                            {/* Device Info */}
                            <Box>
                                <Text size="sm" fw={600} mb="xs">Device Information</Text>
                                <Box style={{
                                    backgroundColor: theme.colors.dark[5],
                                    borderRadius: '8px',
                                    padding: '12px',
                                }}>
                                    <Group gap="xl">
                                        <Box>
                                            <Text size="xs" c="dimmed">Model</Text>
                                            <Text size="sm" fw={500}>{detailedInfo.device_info?.model || 'Unknown'}</Text>
                                        </Box>
                                        <Box>
                                            <Text size="xs" c="dimmed">Serial</Text>
                                            <Text size="sm" fw={500}>{detailedInfo.device_info?.serial || 'Unknown'}</Text>
                                        </Box>
                                        <Box>
                                            <Text size="xs" c="dimmed">Firmware</Text>
                                            <Text size="sm" fw={500}>{detailedInfo.device_info?.firmware || 'Unknown'}</Text>
                                        </Box>
                                        <Box>
                                            <Text size="xs" c="dimmed">Capacity</Text>
                                            <Text size="sm" fw={500}>{detailedInfo.device_info?.capacity || 'Unknown'}</Text>
                                        </Box>
                                        <Box>
                                            <Text size="xs" c="dimmed">Type</Text>
                                            <Text size="sm" fw={500}>{detailedInfo.device_info?.type || 'Unknown'}</Text>
                                        </Box>
                                    </Group>
                                </Box>
                            </Box>

                            {/* Health Status */}
                            <Box>
                                <Text size="sm" fw={600} mb="xs">Health Status</Text>
                                <Box style={{
                                    backgroundColor: theme.colors.dark[5],
                                    borderRadius: '8px',
                                    padding: '12px',
                                }}>
                                    <Group gap="md">
                                        <Badge
                                            size="xl"
                                            color={detailedInfo.health?.passed ? 'green' : 'red'}
                                            variant="light"
                                            leftSection={detailedInfo.health?.passed ? <IconHeart size={18} /> : <IconAlertTriangle size={18} />}
                                        >
                                            {detailedInfo.health?.status || 'UNKNOWN'}
                                        </Badge>
                                        <Box>
                                            <Text size="xs" c="dimmed">SMART Support</Text>
                                            <Text size="sm" fw={500}>
                                                {detailedInfo.smart_support?.available ? (detailedInfo.smart_support?.enabled ? 'Enabled' : 'Disabled') : 'Not Available'}
                                            </Text>
                                        </Box>
                                    </Group>
                                </Box>
                            </Box>

                            {/* Last Test Result */}
                            {detailedInfo.last_test && (
                                <Box>
                                    <Text size="sm" fw={600} mb="xs">Last Test Result</Text>
                                    <Box style={{
                                        backgroundColor: theme.colors.dark[5],
                                        borderRadius: '8px',
                                        padding: '12px',
                                    }}>
                                        <Group gap="xl">
                                            <Box>
                                                <Text size="xs" c="dimmed">Test Type</Text>
                                                <Text size="sm" fw={500}>{detailedInfo.last_test.type}</Text>
                                            </Box>
                                            <Box>
                                                <Text size="xs" c="dimmed">Status</Text>
                                                <Badge
                                                    color={detailedInfo.last_test.status.includes('Completed without error') ? 'green' :
                                                           detailedInfo.last_test.status.includes('Failed') ? 'red' : 'yellow'}
                                                    variant="light"
                                                >
                                                    {detailedInfo.last_test.status}
                                                </Badge>
                                            </Box>
                                            <Box>
                                                <Text size="xs" c="dimmed">Remaining</Text>
                                                <Text size="sm" fw={500}>{detailedInfo.last_test.remaining}%</Text>
                                            </Box>
                                            <Box>
                                                <Text size="xs" c="dimmed">Lifetime Hours</Text>
                                                <Text size="sm" fw={500}>{detailedInfo.last_test.lifetime_hours} hours</Text>
                                            </Box>
                                        </Group>
                                    </Box>
                                </Box>
                            )}

                            <Divider />

                            {/* SMART Attributes */}
                            <Box>
                                <Text size="sm" fw={600} mb="xs">SMART Attributes</Text>
                                <Table striped highlightOnHover>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>Attribute</Table.Th>
                                            <Table.Th>Value</Table.Th>
                                            <Table.Th>Worst</Table.Th>
                                            <Table.Th>Threshold</Table.Th>
                                            <Table.Th>Raw Value</Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        {filteredAttributes.map((attr) => (
                                            <Table.Tr key={attr.id}>
                                                <Table.Td>
                                                    <AttributeCell attr={attr} />
                                                </Table.Td>
                                                <Table.Td>
                                                    <Badge color={attr.value < attr.threshold ? 'red' : 'gray'} variant="light">
                                                        {attr.value}
                                                    </Badge>
                                                </Table.Td>
                                                <Table.Td>{attr.worst}</Table.Td>
                                                <Table.Td>{attr.threshold}</Table.Td>
                                                <Table.Td>
                                                    <Text size="sm" fw={500}>{attr.raw_value.toLocaleString()}</Text>
                                                </Table.Td>
                                            </Table.Tr>
                                        ))}
                                    </Table.Tbody>
                                </Table>
                            </Box>
                        </Stack>
                    </ScrollArea>
                ) : (
                    <Text c="dimmed">Unable to load detailed SMART information.</Text>
                )}
            </Modal>
        </Box>
    );
}
