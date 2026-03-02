import { Box, Text } from '@mantine/core';
import { IconFolder, IconSettings, IconTerminal2, IconBrandDocker, IconChartBar, IconHierarchy2 } from '@tabler/icons-react';
import { useWindow } from './WindowContext';
import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { DragDropContext, Droppable, Draggable } from '@hello-pangea/dnd';

// Fallback apps when none provided from database
const DEFAULT_APPS = [
    { id: 'files', name: 'File Manager', icon: IconFolder, color: '#228be6' },
    { id: 'settings', name: 'Settings', icon: IconSettings, color: '#868e96' },
    { id: 'terminal', name: 'Terminal', icon: IconTerminal2, color: '#40c057' },
    { id: 'docker', name: 'Docker', icon: IconBrandDocker, color: '#228be6' },
    { id: 'monitor', name: 'Monitor', icon: IconChartBar, color: '#fa5252' },
    { id: 'storage', name: 'Storage', icon: IconHierarchy2, color: '#fab005' },
];

// Grid configuration
const ICON_WIDTH = 100;
const ICON_HEIGHT = 100;
const GRID_GAP = 24;
const MARGIN = 20;
const HEADER_HEIGHT = 60;

// Convert percentage to grid position
const percentageToGrid = (percent, gridSize) => {
    if (gridSize <= 1) return 0;
    return Math.round((percent / 100) * (gridSize - 1));
};

// Convert grid position to percentage
const gridToPercentage = (gridPos, gridSize) => {
    if (gridSize <= 1) return 0;
    return Math.round((gridPos / gridSize) * 100 * 100) / 100; // Round to 2 decimal places
};

// Icon component renderer
function IconRenderer({ icon: IconComponent, size, color }) {
    return <IconComponent size={size} color={color} />;
}

export function DesktopIcons({ apps = [], onIconPositionChange }) {
    const { windows, openWindow, focusWindow } = useWindow();
    const [isDragging, setIsDragging] = useState(false);
    const containerRef = useRef(null);
    const [gridDimensions, setGridDimensions] = useState({ columns: 10, rows: 5 });

    // Calculate grid dimensions based on container size
    useEffect(() => {
        const calculateGridDimensions = () => {
            if (!containerRef.current) return;

            const containerWidth = containerRef.current.offsetWidth;
            const containerHeight = containerRef.current.offsetHeight;

            // Calculate available space
            const availableWidth = containerWidth - (MARGIN * 2);
            const availableHeight = containerHeight - HEADER_HEIGHT - (MARGIN * 2);

            // Calculate columns and rows based on icon size and gap
            const columns = Math.max(1, Math.floor(availableWidth / (ICON_WIDTH + GRID_GAP)));
            const rows = Math.max(1, Math.floor(availableHeight / (ICON_HEIGHT + GRID_GAP)));

            setGridDimensions({ columns, rows });
        };

        calculateGridDimensions();

        // Add resize listener
        window.addEventListener('resize', calculateGridDimensions);
        return () => window.removeEventListener('resize', calculateGridDimensions);
    }, []);


    // Get apps to display - use database apps or fallback
    const displayApps = apps.length > 0 ? apps.sort((a, b) => (a.positionX || 0) - (b.positionX || 0)) : DEFAULT_APPS;

    // Create a map for quick app lookup
    const appMap = useMemo(() => {
        const map = {};
        displayApps.forEach(app => {
            map[app.id] = app;
        });
        return map;
    }, [displayApps]);

    // Calculate grid positions from percentage positions
    const gridPositions = useMemo(() => {
        const positions = {};
        displayApps.forEach(app => {
            const percentX = app.positionX ?? 0;
            const percentY = app.positionY ?? 0;

            // Convert percentages to grid positions
            const gridX = percentageToGrid(percentX, gridDimensions.columns);
            const gridY = percentageToGrid(percentY, gridDimensions.rows);

            // Ensure within grid bounds
            const clampedX = Math.min(Math.max(gridX, 0), gridDimensions.columns - 1);
            const clampedY = Math.min(Math.max(gridY, 0), gridDimensions.rows - 1);

            positions[app.id] = { x: clampedX, y: clampedY, percentX, percentY };
        });
        return positions;
    }, [displayApps, gridDimensions.columns, gridDimensions.rows]);

    // Handle double click to open window
    const handleDoubleClick = useCallback((app) => {
        const existingWindow = windows.find(w => w.appId === app.id && !w.minimized);
        if (existingWindow) {
            focusWindow(existingWindow.id);
        } else {
            openWindow(app.id, app.name, app.icon);
        }
    }, [windows, openWindow, focusWindow]);

    // Handle single click to open window
    const handleClick = useCallback((app) => {
        openWindow(app.id, app.name, app.icon);
    }, [openWindow]);

    // Handle drag start
    const handleDragStart = useCallback(() => {
        setIsDragging(true);
    }, []);

    // Handle drag end
    const handleDragEnd = useCallback((result) => {
        setIsDragging(false);

        const { destination, draggableId } = result;

        // Dropped outside the grid
        if (!destination) {
            return;
        }

        const app = appMap[draggableId];
        if (!app || !app.desktopAppId) return;

        // Parse the droppableId to get grid position
        const dropParts = destination.droppableId.split('-');
        if (dropParts[0] !== 'grid') return;

        const targetGridX = parseInt(dropParts[1], 10);
        const targetGridY = parseInt(dropParts[2], 10);

        // Clamp to grid bounds
        const clampedGridX = Math.min(Math.max(targetGridX, 0), gridDimensions.columns - 1);
        const clampedGridY = Math.min(Math.max(targetGridY, 0), gridDimensions.rows - 1);

        // Convert grid positions back to percentages
        const newPercentX = gridToPercentage(clampedGridX, gridDimensions.columns);
        const newPercentY = gridToPercentage(clampedGridY, gridDimensions.rows);

        // Update position using percentages
        if (onIconPositionChange) {
            onIconPositionChange(app.desktopAppId, newPercentX, newPercentY);
        }
    }, [appMap, onIconPositionChange, gridDimensions.columns, gridDimensions.rows]);

    // Generate grid cells
    const gridCells = useMemo(() => {
        const cells = [];
        for (let row = 0; row < gridDimensions.rows; row++) {
            for (let col = 0; col < gridDimensions.columns; col++) {
                const cellId = `grid-${col}-${row}`;
                // Check if this cell is occupied
                const occupiedApp = Object.entries(gridPositions).find(
                    ([, pos]) => pos.x === col && pos.y === row
                );
                cells.push({
                    id: cellId,
                    x: col,
                    y: row,
                    occupiedAppId: occupiedApp ? occupiedApp[0] : null
                });
            }
        }
        return cells;
    }, [gridDimensions.rows, gridDimensions.columns, gridPositions]);

    // Calculate position style for an icon
    const getIconPositionStyle = (gridX, gridY) => {
        return {
            position: 'absolute',
            left: `${MARGIN + gridX * (ICON_WIDTH + GRID_GAP)}px`,
            top: `${HEADER_HEIGHT + MARGIN + gridY * (ICON_HEIGHT + GRID_GAP)}px`,
            width: `${ICON_WIDTH}px`,
            height: `${ICON_HEIGHT}px`,
        };
    };

    return (
        <Box
            ref={containerRef}
            style={{
                position: 'absolute',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
            }}
        >
            <DragDropContext onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
            {/* Grid overlay when dragging */}
            {isDragging && (
                <Box
                    style={{
                        position: 'absolute',
                        top: 0,
                        left: 0,
                        right: 0,
                        bottom: 0,
                        pointerEvents: 'none',
                        zIndex: 999,
                    }}
                >
                    {gridCells.map((cell) => (
                        <Box
                            key={cell.id}
                            style={{
                                position: 'absolute',
                                left: `${MARGIN + cell.x * (ICON_WIDTH + GRID_GAP)}px`,
                                top: `${HEADER_HEIGHT + MARGIN + cell.y * (ICON_HEIGHT + GRID_GAP)}px`,
                                width: `${ICON_WIDTH}px`,
                                height: `${ICON_HEIGHT}px`,
                                border: '2px dashed rgba(255, 255, 255, 0.3)',
                                borderRadius: '8px',
                                backgroundColor: cell.occupiedAppId
                                    ? 'rgba(255, 255, 255, 0.05)'
                                    : 'rgba(255, 255, 255, 0.1)',
                                transition: 'all 0.15s ease',
                            }}
                        />
                    ))}
                </Box>
            )}

            {/* Droppable grid area */}
            <Droppable droppableId="desktop-grid" direction="horizontal">
                {(provided) => (
                    <Box
                        ref={provided.innerRef}
                        {...provided.droppableProps}
                        style={{
                            position: 'absolute',
                            top: 0,
                            left: 0,
                            width: '100%',
                            height: '100%',
                        }}
                    >
                        {displayApps.map((app, index) => {
                            const gridPos = gridPositions[app.id] || { x: 0, y: 0 };

                            return (
                                <Draggable
                                    key={app.id}
                                    draggableId={app.id}
                                    index={index}
                                >
                                    {(provided, snapshot) => (
                                        <Box
                                            ref={provided.innerRef}
                                            {...provided.draggableProps}
                                            {...provided.dragHandleProps}
                                            style={{
                                                ...provided.draggableProps.style,
                                                ...getIconPositionStyle(gridPos.x, gridPos.y),
                                                display: 'flex',
                                                flexDirection: 'column',
                                                alignItems: 'center',
                                                gap: '8px',
                                                padding: '8px',
                                                borderRadius: '8px',
                                                opacity: snapshot.isDragging ? 0.8 : 1,
                                                cursor: 'grab',
                                                zIndex: snapshot.isDragging ? 1000 : 1,
                                            }}
                                            onDoubleClick={() => handleDoubleClick(app)}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                handleClick(app);
                                            }}
                                            className="desktop-icon"
                                        >
                                            <Box
                                                style={{
                                                    width: '64px',
                                                    height: '64px',
                                                    borderRadius: '16px',
                                                    backgroundColor: app.color,
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    boxShadow: snapshot.isDragging
                                                        ? '0 8px 24px rgba(0, 0, 0, 0.4)'
                                                        : '0 4px 12px rgba(0, 0, 0, 0.3)',
                                                }}
                                            >
                                                <IconRenderer icon={app.icon} size={32} color="white" />
                                            </Box>
                                            <Text
                                                size="sm"
                                                c="white"
                                                style={{
                                                    textAlign: 'center',
                                                    textShadow: '0 1px 2px rgba(0, 0, 0, 0.5)',
                                                    fontWeight: 500,
                                                    lineHeight: 1.2,
                                                    maxWidth: '90px',
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                    whiteSpace: 'nowrap',
                                                }}
                                            >
                                                {app.name}
                                            </Text>
                                        </Box>
                                    )}
                                </Draggable>
                            );
                        })}
                        {provided.placeholder}
                    </Box>
                )}
            </Droppable>
        </DragDropContext>
        </Box>
    );
}
