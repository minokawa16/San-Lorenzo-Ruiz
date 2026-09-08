import React, { useState } from 'react';

/**
 * Interface definition matching the database schema requirements.
 */
export interface PositionItem {
  id: string | number;
  position_title: string;
  occupant_name: string | null;
  rank_level: 1 | 2 | 3 | 4 | 5;
  display_order: number;
  is_system_role: boolean;
}

/**
 * Initial canonical seed data matching the 5 tiers.
 */
const initialPositions: PositionItem[] = [
  // Rank 1: Parish Priest (Single occupant)
  {
    id: 1,
    position_title: 'Parish Priest',
    occupant_name: 'Rev. Fr. Alberto Cahilig, OMI',
    rank_level: 1,
    display_order: 1,
    is_system_role: true,
  },
  // Rank 2: Assistant Priest (Parochial Vicar) - Supports 0, 1, or multiple
  {
    id: 2,
    position_title: 'Parochial Vicar',
    occupant_name: 'Rev. Fr. Mark Anthony Santos, OMI',
    rank_level: 2,
    display_order: 1,
    is_system_role: true,
  },
  // Rank 3: Parish Secretary (Single occupant)
  {
    id: 3,
    position_title: 'Parish Secretary',
    occupant_name: 'Ms. Agnes Calapaan',
    rank_level: 3,
    display_order: 1,
    is_system_role: true,
  },
  // Rank 4: PPC Executive Board (Side-by-side peer group)
  {
    id: 4,
    position_title: 'PPC President',
    occupant_name: 'Bro. Eduardo Villanueva',
    rank_level: 4,
    display_order: 1,
    is_system_role: true,
  },
  {
    id: 5,
    position_title: 'PPC Vice President',
    occupant_name: 'Sis. Ma. Cristina Reyes',
    rank_level: 4,
    display_order: 2,
    is_system_role: true,
  },
  {
    id: 6,
    position_title: 'PPC Secretary',
    occupant_name: 'Sis. Teresa Gonzales',
    rank_level: 4,
    display_order: 3,
    is_system_role: true,
  },
  {
    id: 7,
    position_title: 'PPC Treasurer',
    occupant_name: 'Bro. Manuel Santos',
    rank_level: 4,
    display_order: 4,
    is_system_role: true,
  },
  // Rank 5: Dynamic Ministry Coordinators
  {
    id: 8,
    position_title: 'Commission on Liturgy & Sacred Music',
    occupant_name: 'Bro. Rafael Dalisay',
    rank_level: 5,
    display_order: 1,
    is_system_role: false,
  },
  {
    id: 9,
    position_title: 'Parish Youth Ministry (PYM)',
    occupant_name: 'Sis. Angela De Leon',
    rank_level: 5,
    display_order: 2,
    is_system_role: false,
  },
  {
    id: 10,
    position_title: 'Commission on Catechesis & Faith Formation',
    occupant_name: 'Sis. Lourdes Fernandez',
    rank_level: 5,
    display_order: 3,
    is_system_role: false,
  },
  {
    id: 11,
    position_title: 'Ministry of Greeters & Ushers',
    occupant_name: null, // Sample Vacant Position
    rank_level: 5,
    display_order: 4,
    is_system_role: false,
  },
];

export const ParishOrgHierarchy: React.FC = () => {
  const [positions, setPositions] = useState<PositionItem[]>(initialPositions);
  const [isAdminMode, setIsAdminMode] = useState<boolean>(true);
  const [editingId, setEditingId] = useState<string | number | null>(null);
  const [editNameText, setEditNameText] = useState<string>('');
  const [newMinistryTitle, setNewMinistryTitle] = useState<string>('');
  const [showAddMinistry, setShowAddMinistry] = useState<boolean>(false);

  // Direct Name Update (No dropdowns!)
  const handleSaveName = (id: string | number) => {
    setPositions((prev) =>
      prev.map((pos) =>
        pos.id === id
          ? {
              ...pos,
              occupant_name: editNameText.trim() ? editNameText.trim() : null,
            }
          : pos
      )
    );
    setEditingId(null);
    setEditNameText('');
  };

  // Pinned Vacate Action (Never shifts alignment)
  const handleVacate = (id: string | number) => {
    setPositions((prev) =>
      prev.map((pos) => (pos.id === id ? { ...pos, occupant_name: null } : pos))
    );
    if (editingId === id) {
      setEditingId(null);
      setEditNameText('');
    }
  };

  // Add Assistant Priest Slot (Rank 2)
  const handleAddAssistantPriest = () => {
    const rank2List = positions.filter((p) => p.rank_level === 2);
    const newOrder = rank2List.length + 1;
    const newPosition: PositionItem = {
      id: Date.now(),
      position_title: 'Parochial Vicar',
      occupant_name: null,
      rank_level: 2,
      display_order: newOrder,
      is_system_role: false, // Additional vicar slots are removable
    };
    setPositions((prev) => [...prev, newPosition]);
  };

  // Remove Assistant Priest Slot
  const handleRemoveAssistantPriest = (id: string | number) => {
    setPositions((prev) => prev.filter((p) => p.id !== id));
  };

  // Add Dynamic Ministry Coordinator Slot (Rank 5)
  const handleAddMinistry = () => {
    if (!newMinistryTitle.trim()) return;
    const rank5List = positions.filter((p) => p.rank_level === 5);
    const newPosition: PositionItem = {
      id: Date.now(),
      position_title: newMinistryTitle.trim(),
      occupant_name: null,
      rank_level: 5,
      display_order: rank5List.length + 1,
      is_system_role: false,
    };
    setPositions((prev) => [...prev, newPosition]);
    setNewMinistryTitle('');
    setShowAddMinistry(false);
  };

  // Remove Dynamic Ministry Slot
  const handleRemoveMinistry = (id: string | number) => {
    setPositions((prev) => prev.filter((p) => p.id !== id));
  };

  // Filter positions by tier
  const tier1 = positions.find((p) => p.rank_level === 1);
  const tier2 = positions.filter((p) => p.rank_level === 2);
  const tier3 = positions.find((p) => p.rank_level === 3);
  const tier4 = positions.filter((p) => p.rank_level === 4);
  const tier5 = positions.filter((p) => p.rank_level === 5);

  /**
   * Render Standardized Position Card
   * Pinned action button in top-right prevents any misalignment across rows.
   */
  const renderCard = (pos: PositionItem) => {
    const isVacant = !pos.occupant_name;
    const isEditing = editingId === pos.id;

    return (
      <div
        key={pos.id}
        className={`relative flex flex-col justify-between min-h-[160px] p-4 rounded-xl border transition-all ${
          isVacant
            ? 'bg-slate-50/70 border-dashed border-slate-300'
            : 'bg-white border-slate-200 shadow-sm hover:border-slate-300'
        }`}
      >
        {/* Fixed Pinned Actions in Top-Right Corner */}
        {isAdminMode && (
          <div className="absolute top-3 right-3 flex items-center space-x-1">
            {!isVacant && (
              <button
                type="button"
                onClick={() => handleVacate(pos.id)}
                className="text-xs font-medium text-slate-400 hover:text-rose-600 hover:bg-rose-50 px-2 py-1 rounded transition-colors"
                title="Vacate and clear position"
              >
                Vacate
              </button>
            )}

            {/* Extra Assistant Priests or Rank 5 can be removed */}
            {!pos.is_system_role && (
              <button
                type="button"
                onClick={() =>
                  pos.rank_level === 2
                    ? handleRemoveAssistantPriest(pos.id)
                    : handleRemoveMinistry(pos.id)
                }
                className="text-xs font-medium text-slate-400 hover:text-rose-600 hover:bg-rose-50 p-1 rounded transition-colors"
                title="Delete position card"
              >
                ✕
              </button>
            )}
          </div>
        )}

        {/* Card Header */}
        <div className="pr-16">
          <div className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
            Tier {pos.rank_level}
          </div>
          <h3 className="text-sm font-semibold text-slate-900 leading-tight">
            {pos.position_title}
          </h3>
          <div className="mt-1.5 flex items-center">
            {isVacant ? (
              <span className="inline-flex items-center text-[11px] font-medium text-slate-500 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded">
                Vacant
              </span>
            ) : (
              <span className="inline-flex items-center text-[11px] font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse" />
                Active
              </span>
            )}
          </div>
        </div>

        {/* Card Body: Direct Fill-in-the-Blank Input */}
        <div className="mt-3 pt-3 border-t border-slate-100">
          {isAdminMode ? (
            isEditing ? (
              <div className="flex items-center space-x-1.5">
                <input
                  type="text"
                  value={editNameText}
                  onChange={(e) => setEditNameText(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') handleSaveName(pos.id);
                    if (e.key === 'Escape') setEditingId(null);
                  }}
                  placeholder="Enter full name"
                  className="w-full text-xs px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-900 text-slate-900"
                  autoFocus
                />
                <button
                  type="button"
                  onClick={() => handleSaveName(pos.id)}
                  className="text-xs font-medium bg-slate-900 text-white px-2.5 py-1.5 rounded-lg hover:bg-slate-800 transition-colors"
                >
                  Save
                </button>
                <button
                  type="button"
                  onClick={() => setEditingId(null)}
                  className="text-xs text-slate-500 hover:text-slate-800 px-1.5 py-1.5"
                >
                  ✕
                </button>
              </div>
            ) : (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-slate-800 truncate mr-2">
                  {pos.occupant_name || (
                    <span className="text-slate-400 italic text-xs">
                      No occupant assigned
                    </span>
                  )}
                </span>
                <button
                  type="button"
                  onClick={() => {
                    setEditingId(pos.id);
                    setEditNameText(pos.occupant_name || '');
                  }}
                  className="text-xs font-medium text-slate-600 hover:text-slate-900 bg-slate-50 hover:bg-slate-100 border border-slate-200 px-2 py-1 rounded transition-colors whitespace-nowrap"
                >
                  {isVacant ? 'Appoint' : 'Edit'}
                </button>
              </div>
            )
          ) : (
            <div className="text-sm font-medium text-slate-800 truncate">
              {pos.occupant_name || (
                <span className="text-slate-400 italic text-xs">
                  Pending Appointment
                </span>
              )}
            </div>
          )}
        </div>
      </div>
    );
  };

  return (
    <div className="max-w-6xl mx-auto p-6 font-sans text-slate-900">
      {/* Executive Header Toolbar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between pb-6 mb-8 border-b border-slate-200 gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">
            Parish Organizational Hierarchy
          </h1>
          <p className="text-sm text-slate-500 mt-0.5">
            Executive canonical structure, council leadership, and dynamic apostolic apostolates.
          </p>
        </div>

        {/* View Switcher Toggle */}
        <div className="flex items-center bg-slate-100 p-1 rounded-lg border border-slate-200">
          <button
            type="button"
            onClick={() => setIsAdminMode(false)}
            className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-all ${
              !isAdminMode
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Public View
          </button>
          <button
            type="button"
            onClick={() => setIsAdminMode(true)}
            className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-all ${
              isAdminMode
                ? 'bg-slate-900 text-white shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            Admin Editor
          </button>
        </div>
      </div>

      {/* Hierarchy Layout */}
      <div className="space-y-8">
        {/* Tier 1: Parish Priest */}
        <div className="flex flex-col items-center">
          <div className="w-full max-w-md">{tier1 && renderCard(tier1)}</div>
          <div className="w-px h-6 bg-slate-200 my-2" />
        </div>

        {/* Tier 2: Assistant Priest(s) */}
        <div className="flex flex-col items-center">
          <div className="w-full max-w-4xl">
            <div className="flex items-center justify-between mb-3 px-1">
              <div className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                Assistant Priests (Parochial Vicars)
              </div>
              {isAdminMode && (
                <button
                  type="button"
                  onClick={handleAddAssistantPriest}
                  className="text-xs font-semibold text-slate-700 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 border border-slate-300 px-2.5 py-1 rounded-md transition-colors flex items-center space-x-1"
                >
                  <span>+</span>
                  <span>Add Assistant Priest</span>
                </button>
              )}
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {tier2.map((pos) => renderCard(pos))}
            </div>
          </div>
          <div className="w-px h-6 bg-slate-200 my-2" />
        </div>

        {/* Tier 3: Parish Secretary */}
        <div className="flex flex-col items-center">
          <div className="w-full max-w-md">{tier3 && renderCard(tier3)}</div>
          <div className="w-px h-6 bg-slate-200 my-2" />
        </div>

        {/* Tier 4: PPC Executive Board */}
        <div className="w-full max-w-5xl mx-auto">
          <div className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3 px-1">
            Parish Pastoral Council (PPC) Executive Board
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {tier4.map((pos) => renderCard(pos))}
          </div>
          <div className="flex justify-center">
            <div className="w-px h-6 bg-slate-200 my-2" />
          </div>
        </div>

        {/* Tier 5: Ministry Coordinators */}
        <div className="w-full max-w-5xl mx-auto">
          <div className="flex items-center justify-between mb-3 px-1">
            <div className="text-xs font-bold text-slate-500 uppercase tracking-wider">
              Commission & Ministry Coordinators
            </div>
            {isAdminMode && (
              <button
                type="button"
                onClick={() => setShowAddMinistry((prev) => !prev)}
                className="text-xs font-semibold text-slate-700 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 border border-slate-300 px-2.5 py-1 rounded-md transition-colors flex items-center space-x-1"
              >
                <span>+</span>
                <span>Add Ministry Role</span>
              </button>
            )}
          </div>

          {/* Quick Add Ministry Form */}
          {isAdminMode && showAddMinistry && (
            <div className="mb-4 p-3.5 bg-slate-50 border border-slate-200 rounded-xl flex items-center space-x-2">
              <input
                type="text"
                value={newMinistryTitle}
                onChange={(e) => setNewMinistryTitle(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleAddMinistry();
                }}
                placeholder="Enter new ministry title (e.g. Altar Servers Guild)"
                className="flex-grow text-xs px-3 py-2 bg-white border border-slate-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-slate-900"
                autoFocus
              />
              <button
                type="button"
                onClick={handleAddMinistry}
                className="text-xs font-semibold bg-slate-900 text-white px-3.5 py-2 rounded-lg hover:bg-slate-800 transition-colors whitespace-nowrap"
              >
                Add Ministry
              </button>
              <button
                type="button"
                onClick={() => setShowAddMinistry(false)}
                className="text-xs text-slate-500 hover:text-slate-800 px-2"
              >
                Cancel
              </button>
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {tier5.map((pos) => renderCard(pos))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default ParishOrgHierarchy;
